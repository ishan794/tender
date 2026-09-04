<?php

namespace App\Controllers\Api\V1\Authority;

use App\Models\ComplaintModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Buy-side of the complaint / challenge workflow: the procuring organisation
 * reviews challenges to ITS OWN tenders and advances them through the state
 * machine. Every transition is org-scoped and written to the append-only
 * ledger; nothing here can delete a complaint.
 *
 *   submitted → acknowledged → under_review → response_requested → decision
 *   decision → appeal → closed        decision → closed
 */
class ComplaintController extends WorkspaceBase
{
    private const TRANSITIONS = [
        'acknowledge'      => ['from' => ['submitted'],                              'to' => 'acknowledged'],
        'review'           => ['from' => ['acknowledged'],                           'to' => 'under_review'],
        'request_response' => ['from' => ['under_review'],                           'to' => 'response_requested'],
        'decide'           => ['from' => ['under_review', 'response_requested', 'appeal'], 'to' => 'decision'],
        'close'            => ['from' => ['decision', 'appeal'],                     'to' => 'closed'],
    ];

    public function forTender(int $id): ResponseInterface
    {
        // Org-scoped: only the tender's owner can see its challenges.
        if (! $this->procurement($id)) {
            return problem(404, 'not_found', 'No such tender.');
        }

        $rows = model(ComplaintModel::class)
            ->where('procurement_id', $id)
            ->orderBy('id', 'DESC')
            ->findAll();

        return $this->ok($rows);
    }

    public function transition(int $id): ResponseInterface
    {
        $model = model(ComplaintModel::class);
        $c     = $model->find($id);
        if (! $c) {
            return problem(404, 'not_found', 'No such complaint.');
        }

        // The complaint's tender must belong to the caller's organisation.
        if (! $this->procurement((int) $c['procurement_id'])) {
            return problem(403, 'forbidden', 'This challenge is not on one of your tenders.');
        }

        $action = (string) ($this->body()['action'] ?? '');
        if (! isset(self::TRANSITIONS[$action])) {
            return problem(422, 'bad_action', 'Unknown transition.', ['allowed' => array_keys(self::TRANSITIONS)]);
        }

        $rule = self::TRANSITIONS[$action];
        if (! in_array($c['status'], $rule['from'], true)) {
            return problem(409, 'wrong_state', "A '{$c['status']}' complaint cannot be {$action}d.", [
                'expected_from' => $rule['from'],
            ]);
        }

        $in     = $this->body();
        $me     = (int) $this->request->userId;
        $claims = (array) $this->request->claims;
        $upd    = ['status' => $rule['to']];
        $extra  = [];

        if ($action === 'acknowledge') {
            $upd['assigned_reviewer_id']   = $me;
            $upd['assigned_reviewer_name'] = $claims['nm'] ?? null;
        }
        if ($action === 'request_response') {
            $days = max(1, min(60, (int) ($in['response_days'] ?? 7)));
            $upd['response_deadline'] = date('Y-m-d H:i:s', time() + $days * 86400);
            $extra['response_deadline'] = $upd['response_deadline'];
        }
        if ($action === 'decide') {
            $decision = (string) ($in['decision'] ?? '');
            $reason   = trim((string) ($in['decision_reason'] ?? ''));
            if (! in_array($decision, ['upheld', 'rejected', 'partial'], true)) {
                return problem(422, 'validation_failed', 'A decision (upheld, rejected or partial) is required.');
            }
            if ($reason === '') {
                return problem(422, 'validation_failed', 'A decision reason is required.');
            }
            $upd['decision']        = $decision;
            $upd['decision_reason'] = $reason;
            $extra['decision']      = $decision;
        }

        $model->update($id, $upd);

        $summary = ucfirst(str_replace('_', ' ', $action)) . ($action === 'decide' ? " — {$upd['decision']}" : '');
        service('eventLedger')->record('complaint', $id, 'complaint.' . $action, $summary, $extra);
        service('eventLedger')->record('procurement', (int) $c['procurement_id'], 'complaint.' . $action,
            "Challenge {$c['reference']}: {$rule['to']}", $extra);

        return $this->ok($model->find($id));
    }
}
