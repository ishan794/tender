<?php

namespace App\Controllers\Api\V1\Authority;

class TenderController extends WorkspaceBase
{
    public function index()
    {
        $rows = db_connect()->table('procurements')
            ->select('procurements.id, procurements.stage_idx, procurements.approved_at, procurements.published_at,
                      notices.title, notices.reference, notices.slug, notices.closing_at, notices.opening_at,
                      notices.estimated_value, notices.kind, districts.name AS district, categories.name AS category')
            ->join('notices', 'notices.id = procurements.notice_id')
            ->join('districts', 'districts.id = notices.district_id', 'left')
            ->join('categories', 'categories.id = notices.category_id', 'left')
            ->where('procurements.org_id', (int) $this->request->orgId)
            ->orderBy('procurements.updated_at', 'DESC')->get()->getResultArray();

        $db = db_connect();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['stage_idx'] = (int) $r['stage_idx'];
            $r['stage'] = self::STAGES[$r['stage_idx']];
            $r['estimated_value'] = $r['estimated_value'] !== null ? (float) $r['estimated_value'] : null;
            $r['submissions'] = $db->table('submissions')->where('procurement_id', $r['id'])->countAllResults();
            $r['purchasers'] = $db->table('doc_purchases')->where('procurement_id', $r['id'])->countAllResults();
        }

        return $this->ok($rows, ['stages' => self::STAGES]);
    }

    public function create()
    {
        $in    = $this->body();
        $orgId = (int) $this->request->orgId;

        foreach (['title', 'reference', 'closing_at'] as $req) {
            if (trim((string) ($in[$req] ?? '')) === '') {
                return problem(422, 'validation_failed', ucfirst(str_replace('_', ' ', $req)) . ' is required.');
            }
        }

        // Date validation pins an EXPLICIT format. CodeIgniter's bare valid_date
        // accepts anything strtotime() likes, including "yesterday" and
        // "next tuesday" — which it duly accepted in testing.
        $parse = static function (?string $v): ?int {
            if (! $v) {
                return null;
            }
            $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $v)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $v);

            return $d ? $d->getTimestamp() : null;
        };

        $closing = $parse($in['closing_at']);
        $opening = $parse($in['opening_at'] ?? null);

        if ($closing === null) {
            return problem(422, 'bad_date', 'Closing date must be Y-m-d H:i:s.');
        }
        if ($closing < time()) {
            return problem(422, 'closing_in_past', 'Closing cannot be in the past.');
        }
        // Opening cannot precede closing. That is how bids get read while the
        // tender is still open — a data-entry slip with the effect of a leak.
        if ($opening !== null && $opening < $closing) {
            return problem(422, 'opening_before_closing', 'Opening cannot be before closing.');
        }

        $db = db_connect();
        $db->transBegin();

        $slug = url_title($in['reference'] . '-' . $in['title'], '-', true);

        $noticeId = $db->table('notices')->insert([
            'kind' => ($in['kind'] ?? 'tender') === 'auction' ? 'auction' : 'tender',
            'reference' => $in['reference'], 'slug' => $slug, 'title' => $in['title'],
            'summary' => $in['summary'] ?? null, 'description' => $in['description'] ?? null,
            'org_id' => $orgId,
            'category_id' => $in['category_id'] ?? null, 'district_id' => $in['district_id'] ?? null,
            'sector' => $in['sector'] ?? 'government',
            'estimated_value' => $in['estimated_value'] ?? null,
            'document_fee' => $in['document_fee'] ?? null, 'bid_security' => $in['bid_security'] ?? null,
            'contact_officer' => $in['contact_officer'] ?? null,
            'contact_email' => $in['contact_email'] ?? null,
            'contact_phone' => $in['contact_phone'] ?? null,
            'closing_at' => date('Y-m-d H:i:s', $closing),
            'opening_at' => $opening ? date('Y-m-d H:i:s', $opening) : null,
            // Written as draft, so it cannot appear in ANY public query no
            // matter what the workspace does next.
            'status' => 'draft', 'verified' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ], true) ? $db->insertID() : 0;

        $db->table('procurements')->insert([
            'org_id' => $orgId, 'notice_id' => $noticeId, 'stage_idx' => 0,
            'created_by' => (int) $this->request->userId,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $procId = $db->insertID();

        $db->transCommit();

        return $this->ok($this->procurement((int) $procId), [], 201);
    }

    public function show(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        $db = db_connect();
        $proc['stage'] = self::STAGES[(int) $proc['stage_idx']];
        $proc['counts'] = [
            'documents' => $db->table('notice_documents')->where('notice_id', $proc['notice_id'])->countAllResults(),
            'purchasers' => $db->table('doc_purchases')->where('procurement_id', $id)->countAllResults(),
            'submissions' => $db->table('submissions')->where('procurement_id', $id)->countAllResults(),
            'clarifications' => $db->table('clarifications')->where('procurement_id', $id)->countAllResults(),
            'addenda' => $db->table('addenda')->where('procurement_id', $id)->countAllResults(),
        ];

        return $this->ok($proc);
    }

    public function submitForApproval(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }
        if ((int) $proc['stage_idx'] !== 0) {
            return problem(409, 'wrong_stage', 'Only a draft can be submitted for approval.');
        }

        $this->advance($id, 1, ['submitted_by' => (int) $this->request->userId]);
        service('eventLedger')->record('procurement', $id, 'tender.submitted', 'Submitted for approval', [
            'reference' => $proc['reference'] ?? null,
        ]);

        return $this->ok(['stage' => self::STAGES[1]]);
    }

    /**
     * Approving records who signed it off. It does NOT publish. An approver
     * should never discover that clicking approve put the notice on the public
     * site.
     */
    public function approve(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }
        if ((int) $proc['stage_idx'] !== 1) {
            return problem(409, 'wrong_stage', 'This tender is not awaiting approval.');
        }

        $org       = model('App\Models\OrganisationModel')->find((int) $this->request->orgId);
        $threshold = (float) $org['approval_threshold'];
        $value     = (float) ($proc['estimated_value'] ?? 0);
        $me        = (int) $this->request->userId;

        // Refused by the API, not merely hidden in the interface. A rule that
        // only exists in the UI is not a control, it is a suggestion.
        if ($value >= $threshold && (int) $proc['created_by'] === $me) {
            return problem(403, 'self_approval', 'You cannot approve a tender you created.', [
                'threshold' => $threshold,
                'value' => $value,
                'remedy' => 'A different officer with the approver role must sign this off.',
            ]);
        }

        $this->advance($id, 1, ['approved_by' => $me, 'approved_at' => date('Y-m-d H:i:s')]);
        service('eventLedger')->record('procurement', $id, 'tender.approved', 'Approved', [
            'reference' => $proc['reference'] ?? null, 'value' => $value,
        ]);

        return $this->ok(['approved_by' => $me, 'approved_at' => date('c'), 'stage' => self::STAGES[1]], [
            'note' => 'Approved. Publishing is a separate, deliberate act.',
        ]);
    }

    public function publish(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }
        if (! $proc['approved_at']) {
            return problem(409, 'not_approved', 'This tender has not been approved.');
        }
        if ((int) $proc['stage_idx'] >= 2) {
            return problem(409, 'already_published', 'This tender is already published.');
        }
        // A wrong deadline published as fact is the one error that loses a
        // customer permanently.
        if (! $proc['closing_at']) {
            return problem(409, 'no_closing_date', 'A notice cannot be published without a closing date.');
        }

        db_connect()->table('notices')->where('id', $proc['notice_id'])->update([
            'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
        ]);
        $this->advance($id, 2, ['published_by' => (int) $this->request->userId, 'published_at' => date('Y-m-d H:i:s')]);
        service('eventLedger')->record('procurement', $id, 'tender.published', 'Published to the public catalogue', [
            'reference' => $proc['reference'] ?? null,
        ]);

        return $this->ok(['stage' => self::STAGES[2], 'published_at' => date('c')]);
    }
}
