<?php

namespace App\Controllers\Api\V1\Authority;

class AwardController extends WorkspaceBase
{
    public function show(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        $award = db_connect()->table('awards')->where('procurement_id', $id)->get()->getFirstRow('array');

        return $this->ok($award ?: null, $award ? [
            'in_standstill' => strtotime($award['standstill_until']) > time(),
            'standstill_until' => $award['standstill_until'],
        ] : []);
    }

    public function create(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }
        if ((int) $proc['stage_idx'] < 5) {
            return problem(409, 'not_evaluated', 'Evaluation has not begun.');
        }

        $in  = $this->body();
        $ref = trim((string) ($in['committee_ref'] ?? ''));
        if ($ref === '') {
            return problem(422, 'validation_failed', 'A committee approval reference is required.');
        }

        $db  = db_connect();
        $sub = $db->table('submissions')->where('id', (int) ($in['submission_id'] ?? 0))
            ->where('procurement_id', $id)->get()->getFirstRow('array');

        if (! $sub) {
            return problem(404, 'not_found', 'No such submission on this tender.');
        }
        if ((int) $sub['disqualified'] === 1) {
            return problem(409, 'disqualified', 'That bid is disqualified and cannot be awarded.');
        }

        if ($db->table('awards')->where('procurement_id', $id)->countAllResults()) {
            return problem(409, 'already_awarded', 'This tender has already been awarded.');
        }

        $org  = model('App\Models\OrganisationModel')->find((int) $this->request->orgId);
        // Computed by the SERVER from the organisation's configured days, never
        // accepted from the client. A losing bidder's window to challenge is the
        // one date nobody should be able to shorten by sending a number.
        $until = date('Y-m-d H:i:s', time() + ((int) $org['standstill_days'] * 86400));

        $db->transBegin();
        $db->table('awards')->insert([
            'procurement_id' => $id, 'submission_id' => (int) $sub['id'],
            'supplier_org_id' => (int) $sub['bidder_org_id'],
            'amount' => $sub['total_price'], 'committee_ref' => $ref,
            'awarded_by' => (int) $this->request->userId,
            'awarded_at' => date('Y-m-d H:i:s'),
            'standstill_until' => $until,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->advance($id, 6);
        $db->transCommit();

        service('eventLedger')->record('procurement', $id, 'award.created', 'Contract awarded', [
            'committee_ref' => $ref, 'amount' => (float) $sub['total_price'], 'standstill_until' => $until,
        ]);

        return $this->ok([
            'supplier' => $sub['bidder_name'], 'amount' => (float) $sub['total_price'],
            'committee_ref' => $ref, 'awarded_at' => date('c'), 'standstill_until' => $until,
        ], [
            'note' => 'This award becomes publicly listed only after the standstill expires.',
        ], 201);
    }

    /** A rating is anchored to a completed transaction, and there are two
     *  directions. Ratings inform an evaluation committee; they are never an
     *  automatic disqualification, because debarment is a legal act and not a
     *  platform score. */
    public function rate(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        $db    = db_connect();
        $award = $db->table('awards')->where('procurement_id', $id)->get()->getFirstRow('array');
        if (! $award) {
            return problem(409, 'not_awarded', 'This tender has not been awarded.');
        }
        if (strtotime($award['standstill_until']) > time()) {
            return problem(409, 'in_standstill', 'Rating opens when the standstill expires.', [
                'standstill_until' => $award['standstill_until'],
            ]);
        }

        $score = (int) ($this->body()['score'] ?? 0);
        if ($score < 1 || $score > 5) {
            return problem(422, 'bad_score', 'A rating is 1 to 5.');
        }

        $me        = (int) $this->request->orgId;
        $isBuyer   = $me === (int) $proc['org_id'];
        $direction = $isBuyer ? 'buyer_rates_supplier' : 'supplier_rates_buyer';
        $rated     = $isBuyer ? (int) $award['supplier_org_id'] : (int) $proc['org_id'];

        if ($db->table('ratings')->where('award_id', $award['id'])->where('direction', $direction)->countAllResults()) {
            return problem(409, 'already_rated', 'You have already rated this contract.');
        }

        $db->table('ratings')->insert([
            'award_id' => (int) $award['id'], 'direction' => $direction,
            'rater_org_id' => $me, 'rated_org_id' => $rated, 'score' => $score,
            'comment' => $this->body()['comment'] ?? null, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->ok(['rated' => true, 'direction' => $direction], [], 201);
    }

    /**
     * The evidence pack. Assembled from the same rows the workspace reads —
     * there is deliberately NO separate audit log that could drift from what
     * actually happened. This is the artefact a procurement appeal asks for.
     */
    public function evidence(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        $db     = db_connect();
        $events = [];
        $add    = static function (?string $at, string $what, array $detail = []) use (&$events) {
            if ($at) {
                $events[] = ['at' => $at, 'event' => $what] + $detail;
            }
        };

        $add($proc['created_at'], 'created', ['by' => (int) $proc['created_by']]);
        $add($proc['approved_at'], 'approved', ['by' => (int) $proc['approved_by']]);
        $add($proc['published_at'], 'published', ['by' => (int) $proc['published_by']]);

        foreach ($db->table('addenda')->where('procurement_id', $id)->orderBy('number')->get()->getResultArray() as $a) {
            $add($a['created_at'], 'addendum_' . $a['number'], [
                'reason' => $a['reason'], 'new_closing_at' => $a['new_closing_at'], 'by' => (int) $a['issued_by'],
            ]);
        }
        foreach ($db->table('clarifications')->where('procurement_id', $id)->get()->getResultArray() as $c) {
            $add($c['answered_at'], 'clarification_answered', ['id' => (int) $c['id']]);
        }

        if (strtotime((string) $proc['closing_at']) < time()) {
            $add($proc['closing_at'], 'closed');
        }
        $add($proc['opened_at'], 'opened', [
            'officers' => [(int) $proc['opened_by_a'], (int) $proc['opened_by_b']],
        ]);

        $award = $db->table('awards')->where('procurement_id', $id)->get()->getFirstRow('array');
        if ($award) {
            $add($award['awarded_at'], 'awarded', [
                'committee_ref' => $award['committee_ref'], 'amount' => (float) $award['amount'],
                'standstill_until' => $award['standstill_until'],
            ]);
        }

        usort($events, static fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $this->ok([
            'tender' => ['reference' => $proc['reference'], 'title' => $proc['title'],
                         'closing_at' => $proc['closing_at'], 'opening_at' => $proc['opening_at']],
            'timeline' => $events,
            'counts' => [
                'purchasers' => $db->table('doc_purchases')->where('procurement_id', $id)->countAllResults(),
                'submissions' => $db->table('submissions')->where('procurement_id', $id)->countAllResults(),
                'clarifications' => $db->table('clarifications')->where('procurement_id', $id)->countAllResults(),
                'addenda' => $db->table('addenda')->where('procurement_id', $id)->countAllResults(),
                'declarations' => $db->table('coi_declarations')->where('procurement_id', $id)->countAllResults(),
            ],
        ]);
    }
}
