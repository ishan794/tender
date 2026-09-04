<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Models\OrganisationModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Staff review of KYC submissions. This is the ONLY path to 'verified'. It sits
 * behind the admin filter chain (group:staff, entitlement:admin), so a vendor
 * can never reach it — they can submit, never approve themselves.
 */
class KycController extends BaseApiController
{
    public function pending(): ResponseInterface
    {
        $rows = db_connect()->table('kyc_submissions')
            ->select('kyc_submissions.*, organisations.name AS org_name, organisations.verify_state')
            ->join('organisations', 'organisations.id = kyc_submissions.org_id')
            ->where('kyc_submissions.status', 'pending')
            ->orderBy('kyc_submissions.id', 'DESC')->get()->getResultArray();

        return $this->ok($rows);
    }

    public function review(int $id): ResponseInterface
    {
        $db  = db_connect();
        $sub = $db->table('kyc_submissions')->where('id', $id)->get()->getFirstRow('array');
        if (! $sub) {
            return problem(404, 'not_found', 'No such submission.');
        }
        if ($sub['status'] !== 'pending') {
            return problem(409, 'already_reviewed', 'This submission has already been reviewed.');
        }

        $in     = $this->body();
        $action = (string) ($in['action'] ?? '');
        if (! in_array($action, ['approve', 'reject'], true)) {
            return problem(422, 'bad_action', 'action must be approve or reject.');
        }
        if ($action === 'reject' && trim((string) ($in['reason'] ?? '')) === '') {
            return problem(422, 'validation_failed', 'A rejection reason is required.');
        }

        $claims = (array) $this->request->claims;
        $state  = $action === 'approve' ? 'verified' : 'rejected';

        $db->table('kyc_submissions')->where('id', $id)->update([
            'status' => $action === 'approve' ? 'approved' : 'rejected',
            'reviewer_id' => (int) $this->request->userId, 'reviewer_name' => $claims['nm'] ?? null,
            'reason' => $in['reason'] ?? null, 'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        model(OrganisationModel::class)->update((int) $sub['org_id'], array_merge(
            ['verify_state' => $state],
            $action === 'approve' ? ['verified_at' => date('Y-m-d H:i:s')] : [],
        ));
        service('eventLedger')->record('org', (int) $sub['org_id'], 'kyc.' . $action,
            'KYC ' . ($action === 'approve' ? 'approved — organisation verified' : 'rejected'), ['reason' => $in['reason'] ?? null]);

        // Notify the submitter (in-app; email is a provider boundary).
        if ($sub['submitted_by']) {
            (new \App\Libraries\Notifications\NotificationService())->notify(
                (int) $sub['submitted_by'], (int) $sub['org_id'], 'kyc_' . $action,
                $action === 'approve' ? 'Your organisation is verified' : 'KYC submission was not approved',
                $in['reason'] ?? null, '/account', ['in_app', 'email'],
            );
        }

        return $this->ok(['org_id' => (int) $sub['org_id'], 'verify_state' => $state]);
    }

    public function suspend(int $orgId): ResponseInterface
    {
        $org = model(OrganisationModel::class)->find($orgId);
        if (! $org) {
            return problem(404, 'not_found', 'No such organisation.');
        }
        model(OrganisationModel::class)->update($orgId, ['verify_state' => 'suspended']);
        service('eventLedger')->record('org', $orgId, 'kyc.suspended', 'Organisation verification suspended', [
            'reason' => $this->body()['reason'] ?? null,
        ]);

        return $this->ok(['org_id' => $orgId, 'verify_state' => 'suspended']);
    }
}
