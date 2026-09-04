<?php

namespace App\Controllers\Api\V1\Authority;

use App\Models\SubmissionModel;

class EvaluationController extends WorkspaceBase
{
    /**
     * An evaluator who has not declared their interest does not get the sheet
     * AT ALL — not a greyed-out screen. This is refused at the API because a
     * conflicted evaluator who can read the payload has already got what they
     * wanted.
     */
    public function sheet(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        // Evaluation cannot begin before the opening — if it is not opened there
        // is nothing to evaluate, so no price can leak through this route either.
        if (! $this->isOpened($proc)) {
            return problem(409, 'not_opened', 'Bids have not been opened.');
        }

        $db  = db_connect();
        $me  = (int) $this->request->userId;
        $coi = $db->table('coi_declarations')->where('procurement_id', $id)->where('user_id', $me)
            ->get()->getFirstRow('array');

        if (! $coi) {
            return problem(403, 'coi_required', 'Declare your interest before viewing bids.', [
                'declare_at' => "/api/v1/authority/tenders/{$id}/evaluation/coi",
            ]);
        }

        // A declaration is made once and stands. Letting someone withdraw a
        // declared conflict after seeing who bid would defeat the point.
        if ((int) $coi['has_conflict'] === 1) {
            return problem(403, 'conflicted', 'You declared a conflict on this tender.', [
                'permanent' => true,
            ]);
        }

        return $this->ok([
            'criteria' => $db->table('eval_criteria')->where('procurement_id', $id)->get()->getResultArray(),
            'submissions' => model(SubmissionModel::class)->forProcurement($id, true),
            'my_scores' => $db->table('eval_scores')
                ->select('eval_scores.*')
                ->join('submissions', 'submissions.id = eval_scores.submission_id')
                ->where('submissions.procurement_id', $id)
                ->where('eval_scores.evaluator_id', $me)->get()->getResultArray(),
        ], ['stage' => self::STAGES[(int) $proc['stage_idx']]]);
    }

    public function declare(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        $db = db_connect();
        $me = (int) $this->request->userId;
        if ($db->table('coi_declarations')->where('procurement_id', $id)->where('user_id', $me)->countAllResults()) {
            return problem(409, 'already_declared', 'You have already declared on this tender.');
        }

        $hasConflict = ! empty($this->body()['has_conflict']) ? 1 : 0;
        $db->table('coi_declarations')->insert([
            'procurement_id' => $id, 'user_id' => $me,
            'has_conflict' => $hasConflict,
            'statement' => $this->body()['statement'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        service('eventLedger')->record('procurement', $id, 'eval.coi_declared', 'Conflict of interest declared', [
            'has_conflict' => $hasConflict,
        ]);

        return $this->ok(['declared' => true], [], 201);
    }

    public function criteria(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        $db = db_connect();
        foreach ($this->body()['criteria'] ?? [] as $c) {
            $db->table('eval_criteria')->insert([
                'procurement_id' => $id, 'label' => $c['label'],
                'type' => ($c['type'] ?? 'weighted') === 'pass_fail' ? 'pass_fail' : 'weighted',
                'weight' => $c['weight'] ?? 0, 'max_score' => $c['max_score'] ?? 100,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->ok($db->table('eval_criteria')->where('procurement_id', $id)->get()->getResultArray(), [], 201);
    }

    public function score(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }
        if (! $this->isOpened($proc)) {
            return problem(409, 'not_opened', 'Bids have not been opened.');
        }

        $db  = db_connect();
        $me  = (int) $this->request->userId;
        $coi = $db->table('coi_declarations')->where('procurement_id', $id)->where('user_id', $me)
            ->get()->getFirstRow('array');
        if (! $coi || (int) $coi['has_conflict'] === 1) {
            return problem(403, 'coi_required', 'You are not cleared to score this tender.');
        }

        /**
         * A silent bug worth remembering: submission ids were compared strictly
         * against ids read back from the database. SQLite returns them as
         * strings and MySQL as integers, so in_array($id, $valid, true) dropped
         * every score on one driver and none on the other — the endpoint
         * reported success and saved nothing. BOTH sides are cast to int.
         */
        $validSubs = array_map('intval', array_column(
            $db->table('submissions')->select('id')->where('procurement_id', $id)->get()->getResultArray(), 'id'
        ));
        $validCrit = array_map('intval', array_column(
            $db->table('eval_criteria')->select('id')->where('procurement_id', $id)->get()->getResultArray(), 'id'
        ));

        $saved = 0;
        $ignored = 0;

        foreach ($this->body()['scores'] ?? [] as $s) {
            $sub  = (int) ($s['submission_id'] ?? 0);
            $crit = (int) ($s['criterion_id'] ?? 0);

            // A score cannot be written onto another organisation's submission
            // by sending its id.
            if (! in_array($sub, $validSubs, true) || ! in_array($crit, $validCrit, true)) {
                $ignored++;
                continue;
            }

            $consensus = ! empty($s['is_consensus']) ? 1 : 0;
            $where = ['submission_id' => $sub, 'criterion_id' => $crit, 'evaluator_id' => $me, 'is_consensus' => $consensus];
            $row   = $db->table('eval_scores')->where($where)->get()->getFirstRow('array');

            $data = [
                'score' => $s['score'] ?? null,
                'passed' => isset($s['passed']) ? (int) (bool) $s['passed'] : null,
                'note' => $s['note'] ?? null,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($row) {
                // Consensus never overwrites what an individual evaluator recorded:
                // they are distinct rows by unique key.
                $db->table('eval_scores')->where('id', $row['id'])->update($data);
            } else {
                $db->table('eval_scores')->insert($where + $data + ['created_at' => date('Y-m-d H:i:s')]);
            }
            $saved++;
        }

        // The stage only advances if something actually saved.
        if ($saved > 0) {
            if ((int) $proc['stage_idx'] < 5) {
                $this->advance($id, 5);
            }
            service('eventLedger')->record('procurement', $id, 'eval.scored', 'Evaluation score(s) recorded', [
                'saved' => $saved,
            ]);
        }

        return $this->ok(['saved' => $saved, 'ignored' => $ignored], [
            'stage' => self::STAGES[max((int) $proc['stage_idx'], $saved > 0 ? 5 : (int) $proc['stage_idx'])],
        ]);
    }
}
