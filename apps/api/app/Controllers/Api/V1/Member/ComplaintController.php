<?php

namespace App\Controllers\Api\V1\Member;

use App\Controllers\Api\V1\BaseApiController;
use App\Models\ComplaintModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Bidder side of the complaint / challenge workflow: file a challenge against a
 * published tender, track your own challenges, and appeal a decision. There is
 * deliberately no delete endpoint — a complaint, once filed, is permanent.
 */
class ComplaintController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $rows = model(ComplaintModel::class)
            ->where('complainant_org_id', (int) $this->request->orgId)
            ->orderBy('id', 'DESC')
            ->findAll();

        return $this->ok($rows);
    }

    public function create(): ResponseInterface
    {
        $in      = $this->body();
        $procId  = (int) ($in['procurement_id'] ?? 0);
        $grounds = trim((string) ($in['grounds'] ?? ''));

        if ($procId <= 0) {
            return problem(422, 'validation_failed', 'A tender reference is required.');
        }
        if (mb_strlen($grounds) < 20) {
            return problem(422, 'validation_failed', 'Describe the grounds for the challenge (at least 20 characters).');
        }

        // You can only challenge a REAL, published tender. stage_idx >= 2 == published.
        $proc = db_connect()->table('procurements')
            ->select('procurements.id, procurements.stage_idx, notices.reference')
            ->join('notices', 'notices.id = procurements.notice_id')
            ->where('procurements.id', $procId)
            ->get()->getFirstRow('array');

        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }
        if ((int) $proc['stage_idx'] < 2) {
            return problem(409, 'not_public', 'Only a published tender can be challenged.');
        }

        $model  = model(ComplaintModel::class);
        $n      = $model->where('procurement_id', $procId)->countAllResults() + 1;
        $ref    = 'CMP-' . $procId . '-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        $claims = (array) $this->request->claims;

        $id = $model->insert([
            'reference'           => $ref,
            'procurement_id'      => $procId,
            'notice_ref'          => $proc['reference'],
            'complainant_org_id'  => (int) $this->request->orgId,
            'complainant_user_id' => (int) $this->request->userId,
            'complainant_name'    => $claims['nm'] ?? null,
            'grounds'             => $grounds,
            'status'              => 'submitted',
        ], true);

        // Two ledger entries: one on the complaint's own chain, one on the
        // tender's chain so the challenge appears in the procurement timeline.
        service('eventLedger')->record('complaint', (int) $id, 'complaint.submitted', "Challenge {$ref} submitted", [
            'procurement_id' => $procId, 'tender' => $proc['reference'],
        ]);
        service('eventLedger')->record('procurement', $procId, 'complaint.submitted', "A challenge was submitted ({$ref})", [
            'complaint_ref' => $ref,
        ]);

        return $this->ok($model->find($id), [], 201);
    }

    public function appeal(int $id): ResponseInterface
    {
        $model = model(ComplaintModel::class);
        $c     = $model->where('id', $id)
            ->where('complainant_org_id', (int) $this->request->orgId)
            ->first();

        if (! $c) {
            return problem(404, 'not_found', 'No such complaint.');
        }
        if ($c['status'] !== 'decision') {
            return problem(409, 'wrong_state', 'Only a complaint with a decision can be appealed.');
        }

        $model->update($id, ['status' => 'appeal']);
        service('eventLedger')->record('complaint', $id, 'complaint.appealed', 'Decision appealed by the complainant');
        service('eventLedger')->record('procurement', (int) $c['procurement_id'], 'complaint.appealed', "Challenge {$c['reference']} appealed");

        return $this->ok($model->find($id));
    }
}
