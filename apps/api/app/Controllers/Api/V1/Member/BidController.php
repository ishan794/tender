<?php

namespace App\Controllers\Api\V1\Member;

use App\Controllers\Api\V1\BaseApiController;

class BidController extends BaseApiController
{
    private const STAGES = ['watching', 'preparing', 'ready', 'submitted', 'won', 'lost'];

    public function index()
    {
        $rows = db_connect()->table('bids')
            ->select('bids.*, notices.title, notices.reference, notices.slug, notices.closing_at,
                      notices.estimated_value, districts.name AS district')
            ->join('notices', 'notices.id = bids.notice_id')
            ->join('districts', 'districts.id = notices.district_id', 'left')
            ->where('bids.org_id', (int) $this->request->orgId)
            ->orderBy('notices.closing_at', 'ASC')->get()->getResultArray();

        return $this->ok(array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'stage' => $r['stage'],
            'notice' => ['id' => (int) $r['notice_id'], 'title' => $r['title'], 'slug' => $r['slug'],
                         'reference' => $r['reference'], 'closing_at' => $r['closing_at'],
                         'district' => $r['district'],
                         'estimated_value' => $r['estimated_value'] !== null ? (float) $r['estimated_value'] : null],
            'checklist' => ['ready' => (int) $r['checklist_ready'], 'total' => (int) $r['checklist_total']],
            'can_submit' => self::canMoveTo($r, 'submitted'),
            'notes' => $r['notes'],
        ], $rows), ['stages' => self::STAGES]);
    }

    public function create()
    {
        $in = $this->body();
        $id = model('App\Models\NoticeModel')->find((int) ($in['notice_id'] ?? 0));
        if (! $id) {
            return problem(404, 'not_found', 'No such notice.');
        }

        $bid = db_connect()->table('bids');
        $existing = $bid->where('org_id', (int) $this->request->orgId)
            ->where('notice_id', (int) $in['notice_id'])->get()->getFirstRow('array');
        if ($existing) {
            return $this->ok($existing);
        }

        $bid->insert([
            'org_id' => (int) $this->request->orgId, 'notice_id' => (int) $in['notice_id'],
            'stage' => 'watching', 'owner_id' => (int) $this->request->userId,
            'checklist_total' => (int) ($in['checklist_total'] ?? 6),
            'checklist_ready' => 0,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->ok(['created' => true], [], 201);
    }

    public function move(int $id)
    {
        $db  = db_connect();
        $bid = $db->table('bids')->where('id', $id)->where('org_id', (int) $this->request->orgId)
            ->get()->getFirstRow('array');
        if (! $bid) {
            return problem(404, 'not_found', 'No such bid.');
        }

        $to = (string) ($this->body()['stage'] ?? '');
        if (! in_array($to, self::STAGES, true)) {
            return problem(422, 'bad_stage', 'Unknown stage.');
        }

        if (! self::canMoveTo($bid, $to)) {
            return problem(409, 'checklist_incomplete', 'This bid is not ready to submit.', [
                'ready' => (int) $bid['checklist_ready'], 'total' => (int) $bid['checklist_total'],
            ]);
        }

        $db->table('bids')->where('id', $id)->update(['stage' => $to, 'updated_at' => date('Y-m-d H:i:s')]);

        return $this->ok(['stage' => $to]);
    }

    /** A bid cannot move to submitted while its checklist is incomplete. */
    private static function canMoveTo(array $bid, string $to): bool
    {
        if ($to !== 'submitted') {
            return true;
        }

        return (int) $bid['checklist_total'] > 0
            && (int) $bid['checklist_ready'] >= (int) $bid['checklist_total'];
    }

    /**
     * E-submission. The SERVER clock decides, never the browser's: a bid lodged
     * one second late is late, and that boundary is the entire reason
     * electronic submission exists.
     */
    public function lodge()
    {
        $in    = $this->body();
        $orgId = (int) $this->request->orgId;
        $db    = db_connect();

        $proc = $db->table('procurements')
            ->select('procurements.*, notices.closing_at, notices.status AS notice_status, notices.title')
            ->join('notices', 'notices.id = procurements.notice_id')
            ->where('procurements.id', (int) ($in['procurement_id'] ?? 0))
            ->get()->getFirstRow('array');

        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        if ((int) $proc['stage_idx'] !== 2 || $proc['notice_status'] !== 'published') {
            return problem(409, 'not_open', 'This tender is not open for submissions.');
        }

        if (strtotime((string) $proc['closing_at']) < time()) {
            return problem(409, 'closed', 'This tender closed at ' . $proc['closing_at'] . ' (server time).', [
                'closed_at' => $proc['closing_at'], 'server_now' => date('c'),
            ]);
        }

        // Lodging requires having bought the documents.
        $bought = $db->table('doc_purchases')->where('procurement_id', $proc['id'])
            ->where('buyer_org_id', $orgId)->countAllResults() > 0;
        if (! $bought) {
            return problem(403, 'documents_not_purchased', 'You must buy the bidding documents before lodging a bid.');
        }

        $existing = $db->table('submissions')->where('procurement_id', $proc['id'])
            ->where('bidder_org_id', $orgId)->get()->getFirstRow('array');
        if ($existing) {
            // Once lodged it cannot be altered.
            return problem(409, 'already_submitted', 'You have already lodged a bid for this tender.', [
                'reference' => $existing['reference'],
            ]);
        }

        $org     = model('App\Models\OrganisationModel')->find($orgId);
        $payload = json_encode($in['envelope'] ?? []);
        $ref     = sprintf('SUB-%d-%04d', $proc['id'],
            $db->table('submissions')->where('procurement_id', $proc['id'])->countAllResults() + 1);

        $id = $db->table('submissions')->insert([
            'procurement_id' => (int) $proc['id'], 'bidder_org_id' => $orgId,
            'bidder_name' => $org['name'], 'reference' => $ref,
            'total_price' => $in['total_price'] ?? null,
            'has_security' => ! empty($in['has_security']) ? 1 : 0,
            'size_bytes' => strlen($payload),
            // A content hash is recorded at lodgement, so what was submitted can
            // be proved later (Electronic Transactions Act No. 19 of 2006).
            'content_hash' => hash('sha256', $payload),
            'cipher_path' => null,
            'status' => 'submitted',
            'received_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ], true) ? $db->insertID() : 0;

        // Seal the sensitive fields under the per-tender key, then remove the
        // plaintext from the row. From here until the dual-control opening
        // decrypts it, a database dump yields only ciphertext — the bid is
        // encrypted at rest, not merely withheld by a query.
        if ($id) {
            service('crypto')->seal((int) $proc['id'], (int) $id, [
                'bidder_name'  => $org['name'],
                'total_price'  => $in['total_price'] ?? null,
                'has_security' => ! empty($in['has_security']) ? 1 : 0,
            ]);
            // Replace the plaintext with non-informative sealed placeholders —
            // the real values exist only inside the encrypted bid_seals row.
            $db->table('submissions')->where('id', $id)->update([
                'bidder_name' => '(sealed)', 'total_price' => 0, 'has_security' => 0, 'cipher_path' => 'sealed',
            ]);
        }

        return $this->ok([
            'id' => (int) $id, 'reference' => $ref, 'received_at' => date('c'),
            'content_hash' => hash('sha256', $payload),
        ], ['note' => 'Keep this receipt. It is your proof you were on time.'], 201);
    }

    /** Readable only by the organisation that lodged it. */
    public function receipt(int $id)
    {
        $row = db_connect()->table('submissions')
            ->select('submissions.*, notices.title, notices.reference AS tender_ref, organisations.name AS buyer')
            ->join('procurements', 'procurements.id = submissions.procurement_id')
            ->join('notices', 'notices.id = procurements.notice_id')
            ->join('organisations', 'organisations.id = procurements.org_id', 'left')
            ->where('submissions.id', $id)
            ->where('submissions.bidder_org_id', (int) $this->request->orgId)
            ->get()->getFirstRow('array');

        if (! $row) {
            return problem(404, 'not_found', 'No such receipt.');
        }

        return $this->ok([
            'reference' => $row['reference'], 'tender' => $row['title'],
            'tender_reference' => $row['tender_ref'], 'buyer' => $row['buyer'],
            'received_at' => $row['received_at'], 'content_hash' => $row['content_hash'],
            'status' => $row['status'],
        ]);
    }

    public function vault()
    {
        return $this->ok(db_connect()->table('document_assets')
            ->where('org_id', (int) $this->request->orgId)->orderBy('expires_at', 'ASC')
            ->get()->getResultArray());
    }
}
