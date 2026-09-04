<?php

namespace App\Controllers\Api\V1\Account;

use App\Controllers\Api\V1\BaseApiController;
use App\Models\OrganisationModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * An organisation submits KYC documents for verification. Submitting only ever
 * moves verify_state to 'pending' — an org can NEVER set itself to 'verified'.
 * Approval is staff-only (Admin\KycController).
 */
class KycController extends BaseApiController
{
    public function status(): ResponseInterface
    {
        $org = model(OrganisationModel::class)->find((int) $this->request->orgId);
        $subs = db_connect()->table('kyc_submissions')->where('org_id', (int) $this->request->orgId)
            ->orderBy('id', 'DESC')->get()->getResultArray();

        return $this->ok(['verify_state' => $org['verify_state'], 'submissions' => $subs]);
    }

    public function submit(): ResponseInterface
    {
        $in    = $this->body();
        $cats  = $in['categories'] ?? [];
        if (! is_array($cats) || $cats === []) {
            return problem(422, 'validation_failed', 'List the document categories you are submitting.');
        }
        $orgId  = (int) $this->request->orgId;
        $claims = (array) $this->request->claims;
        $db     = db_connect();

        // An org cannot re-submit while a review is already pending.
        if ($db->table('kyc_submissions')->where('org_id', $orgId)->where('status', 'pending')->countAllResults()) {
            return problem(409, 'already_pending', 'A KYC submission is already under review.');
        }

        $db->table('kyc_submissions')->insert([
            'org_id' => $orgId, 'submitted_by' => (int) $this->request->userId,
            'categories' => json_encode(array_values($cats)), 'notes' => $in['notes'] ?? null,
            'status' => 'pending', 'submitted_at' => date('Y-m-d H:i:s'),
        ]);

        // Submission only ever moves the org to 'pending' — never 'verified'.
        model(OrganisationModel::class)->update($orgId, ['verify_state' => 'pending']);
        service('eventLedger')->record('org', $orgId, 'kyc.submitted', 'KYC documents submitted for verification', [
            'categories' => array_values($cats),
        ], ['id' => (int) $this->request->userId, 'name' => $claims['nm'] ?? null, 'role' => $claims['role'] ?? null, 'org' => $orgId]);

        return $this->ok(['verify_state' => 'pending', 'submission_id' => $db->insertID()], [], 201);
    }
}
