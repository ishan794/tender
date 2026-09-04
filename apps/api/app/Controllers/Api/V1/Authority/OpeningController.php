<?php

namespace App\Controllers\Api\V1\Authority;

use App\Models\SubmissionModel;

/**
 * The part of this product that has to be right. Everything else is
 * convenience; this is the thing a procurement officer stakes their career on.
 */
class OpeningController extends WorkspaceBase
{
    public function submissions(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        $opened = $this->isOpened($proc);
        $rows   = model(SubmissionModel::class)->forProcurement($id, $opened);

        $meta = ['opened' => $opened, 'stage' => self::STAGES[(int) $proc['stage_idx']]];

        if (! $opened) {
            // "Why can I not see this" is the first question every procurement
            // officer asks. Answering it in the payload is cheaper than
            // answering it on the phone.
            $meta['withheld'] = SubmissionModel::WITHHELD;
            $meta['withheld_reason'] = SubmissionModel::WITHHELD_REASON;
            $meta['opens_at'] = $proc['opening_at'];
        }

        return $this->ok($rows, $meta);
    }

    /** Step one of dual control. */
    public function start(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }
        if ($this->isOpened($proc)) {
            return problem(409, 'already_opened', 'This tender has already been opened.');
        }

        if ($proc['opening_at'] && strtotime($proc['opening_at']) > time()) {
            return problem(409, 'too_early', 'The opening time has not arrived.', [
                'opens_at' => $proc['opening_at'],
                'server_now' => date('c'),
            ]);
        }

        db_connect()->table('procurements')->where('id', $id)->update([
            'opened_by_a' => (int) $this->request->userId,
            'opening_started_at' => date('Y-m-d H:i:s'),
            'stage_idx' => 3,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        service('eventLedger')->record('procurement', $id, 'opening.started', 'Opening ceremony started (officer A)');

        return $this->ok(['started_by' => (int) $this->request->userId, 'started_at' => date('c')], [
            'next' => 'A DIFFERENT officer must countersign before any bid can be read.',
        ]);
    }

    /**
     * Only the countersignature flips the stage to Opened. Two DISTINCT
     * officers are required: one person holding both halves is not dual
     * control, and the whole point of the ceremony is that no single person can
     * see a bid early.
     */
    public function countersign(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }
        if ($this->isOpened($proc)) {
            return problem(409, 'already_opened', 'This tender has already been opened.');
        }
        if (! $proc['opened_by_a']) {
            return problem(409, 'not_started', 'Nobody has started the opening ceremony.');
        }

        $me = (int) $this->request->userId;
        if ((int) $proc['opened_by_a'] === $me) {
            return problem(403, 'same_officer', 'The officer who started the opening cannot countersign it.', [
                'started_by' => (int) $proc['opened_by_a'],
                'remedy' => 'A second authorised officer must countersign.',
            ]);
        }

        $db = db_connect();
        $db->transBegin();
        $db->table('procurements')->where('id', $id)->update([
            'opened_by_b' => $me, 'opened_at' => date('Y-m-d H:i:s'),
            'stage_idx' => 4, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $db->table('submissions')->where('procurement_id', $id)->update(['status' => 'opened']);
        $db->transCommit();

        service('eventLedger')->record('procurement', $id, 'opening.countersigned', 'Opening countersigned by a second officer — bids readable', [
            'opened_by' => [(int) $proc['opened_by_a'], $me],
        ]);

        // Dual control has completed: NOW decrypt the sealed bids and write the
        // readable values back. Before this point the plaintext did not exist in
        // the database at all. Legacy (unsealed) rows are left untouched.
        foreach (service('crypto')->unsealAll($id) as $subId => $data) {
            if ($data) {
                $db->table('submissions')->where('id', $subId)->update([
                    'bidder_name'  => $data['bidder_name'] ?? null,
                    'total_price'  => $data['total_price'] ?? null,
                    'has_security' => $data['has_security'] ?? 0,
                ]);
            }
        }

        $rows = model(SubmissionModel::class)->forProcurement($id, true);

        return $this->ok($rows, [
            'opened' => true,
            'opened_by' => [(int) $proc['opened_by_a'], $me],
            'opened_at' => date('c'),
            'note' => 'Read out in the ceremony and recorded. From here on these figures are '
                . 'visible to the committee.',
        ]);
    }
}
