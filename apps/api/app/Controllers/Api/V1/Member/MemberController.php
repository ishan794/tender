<?php

namespace App\Controllers\Api\V1\Member;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\DocumentStore;
use App\Models\AlertProfileModel;
use App\Models\NoticeModel;
use App\Transformers\NoticeTransformer;
use Config\Subscription;

class MemberController extends BaseApiController
{
    /**
     * Each active profile is matched SEPARATELY and the results merged, with
     * the profile name attached to each row. One combined query would OR the
     * conditions together and match notices no single profile actually wants.
     *
     * Saying which profile matched is not decoration: a feed full of
     * irrelevant notices with no explanation gets ignored; one that says
     * "matched: Civil works — Western" gets the profile edited, and an edited
     * profile is a renewed subscription.
     */
    public function feed()
    {
        $orgId    = (int) $this->request->orgId;
        $profiles = model(AlertProfileModel::class)->where('org_id', $orgId)->where('active', 1)->findAll();

        if (! $profiles) {
            return $this->ok([], [
                'empty_reason' => 'no_profiles',
                'empty_help'   => 'Create an alert profile to start receiving matches. '
                    . 'You can preview what it would have matched before you save it.',
            ]);
        }

        $model   = model(NoticeModel::class);
        $matched = [];
        foreach ($profiles as $p) {
            foreach ($model->matchIdsFor($p) as $id) {
                $matched[$id]['profiles'][] = $p['name'];
            }
        }

        if (! $matched) {
            return $this->ok([], [
                'empty_reason' => 'no_matches',
                'empty_help'   => 'Your profiles are valid but nothing has matched yet. '
                    . 'Widening the district or removing a keyword is usually enough.',
                'profiles' => count($profiles),
            ]);
        }

        $tier = NoticeTransformer::tierFor(['plan' => $this->request->plan, 'sub_status' => 'active']);
        $rows = $model->byIds(array_keys($matched));

        $out = [];
        foreach ($rows as $r) {
            $item = NoticeTransformer::one($r, $tier);
            $item['matched_by'] = array_values(array_unique($matched[$r['id']]['profiles']));
            $out[] = $item;
        }

        return $this->ok($out, ['profiles' => count($profiles), 'tier' => $tier]);
    }

    public function notice(string $slug)
    {
        $notice = model(NoticeModel::class)->bySlug($slug);
        if (! $notice || $notice['status'] !== 'published') {
            return problem(404, 'not_found', 'No such notice.');
        }

        $docs = model('App\Models\NoticeDocumentModel')->where('notice_id', $notice['id'])->findAll();

        return $this->ok(NoticeTransformer::one($notice, 'paid', [
            'documents' => array_map(static fn ($d) => [
                'id' => (int) $d['id'], 'name' => $d['name'], 'kind' => $d['kind'],
                'size_bytes' => (int) $d['size_bytes'], 'sha256' => $d['sha256'],
                'available' => (bool) $d['mirrored_at'],
                'reason' => $d['mirrored_at'] ? null : ($d['mirror_error'] ?: 'not_mirrored'),
                'source_url' => $d['source_url'],
            ], $docs),
        ]));
    }

    /**
     * Minted on click, never rendered into the page: a five-minute link
     * embedded in HTML is dead by the time most people click it — and alive
     * long enough to be forwarded if they do.
     */
    public function documentUrl(int $noticeId, int $docId)
    {
        $doc = model('App\Models\NoticeDocumentModel')->find($docId);

        if (! $doc || (int) $doc['notice_id'] !== $noticeId) {
            return problem(404, 'not_found', 'No such document.');
        }

        if (! $doc['mirrored_at'] || ! $doc['path']) {
            return problem(409, 'not_mirrored', 'We have not mirrored this file yet.', [
                'source_url' => $doc['source_url'],
            ]);
        }

        $db = db_connect();
        $proc = $db->table('procurements')->where('notice_id', $noticeId)->get()->getFirstRow('array');
        $notice = model('App\Models\NoticeModel')->find($noticeId);
        if ($proc && $notice && ((float) ($notice['document_fee'] ?? 0)) > 0 && ($doc['kind'] ?? 'bidding') === 'bidding') {
            $bought = $db->table('doc_purchases')
                ->where('procurement_id', $proc['id'])
                ->where('buyer_org_id', (int) $this->request->orgId)
                ->countAllResults() > 0;
            if (! $bought) {
                return problem(403, 'documents_not_purchased', 'You must buy the bidding documents before accessing download links.', [
                    'fee'      => (float) $notice['document_fee'],
                    'currency' => $notice['currency'] ?? 'LKR',
                    'buy_url'  => "/api/v1/me/tenders/{$proc['id']}/buy-documents",
                ]);
            }
        }

        $expires = time() + 300;
        $userId  = (int) $this->request->userId;
        $sig     = DocumentStore::sign($docId, $userId, $expires);

        return $this->ok([
            'url' => sprintf('/api/v1/files/documents/%d?u=%d&e=%d&s=%s', $docId, $userId, $expires, $sig),
            'expires_at' => date('c', $expires),
            'name' => $doc['name'],
            'size_bytes' => (int) $doc['size_bytes'],
            'sha256' => $doc['sha256'],
        ]);
    }

    // ------------------------------------------------------------ alerts
    public function profiles()
    {
        return $this->ok(model(AlertProfileModel::class)->where('org_id', (int) $this->request->orgId)->findAll());
    }

    public function createProfile()
    {
        $in = $this->body();
        if (trim((string) ($in['name'] ?? '')) === '') {
            return problem(422, 'validation_failed', 'A profile needs a name.');
        }

        $csv = static fn ($v) => is_array($v) ? implode(',', $v) : (string) ($v ?? '');

        $id = model(AlertProfileModel::class)->insert([
            'org_id' => (int) $this->request->orgId,
            'user_id' => (int) $this->request->userId,
            'name' => $in['name'],
            'kinds' => $csv($in['kinds'] ?? 'tender') ?: 'tender',
            'category_slugs' => $csv($in['categories'] ?? ''),
            'district_slugs' => $csv($in['districts'] ?? ''),
            'keywords' => $csv($in['keywords'] ?? ''),
            'min_value' => $in['min_value'] ?? null,
            'max_value' => $in['max_value'] ?? null,
            'channels' => $csv($in['channels'] ?? 'inapp') ?: 'inapp',
            'active' => 1,
        ], true);

        return $this->ok(model(AlertProfileModel::class)->find($id), [
            'delivery_note' => 'Matching and the in-app feed are live. E-mail, SMS and '
                . 'WhatsApp delivery are not yet wired.',
        ], 201);
    }

    /**
     * The dry run. The highest-converting screen in the product: a profile that
     * never fires is worse than no profile, and nobody can tell which they have
     * built without seeing it tested against real history.
     */
    public function previewProfile(int $id)
    {
        $model   = model(AlertProfileModel::class);
        $profile = $model->where('org_id', (int) $this->request->orgId)->find($id);

        if (! $profile) {
            return problem(404, 'not_found', 'No such profile.');
        }

        $since = date('Y-m-d H:i:s', strtotime('-30 days'));
        $ids   = $model->matchIds($profile, $since);
        $perWeek = round(count($ids) / (30 / 7), 1);

        $sample = $ids ? model(NoticeModel::class)->byIds(array_slice($ids, 0, 5)) : [];

        $warning = null;
        if ($perWeek >= 7 && preg_match('/sms|whatsapp/', (string) $profile['channels'])) {
            $warning = sprintf(
                'This profile would fire about %s times a week on a paid channel. '
                . 'Narrow it, or move it to the in-app feed.',
                $perWeek
            );
        }

        return $this->ok([
            'window_days' => 30,
            'matches'     => count($ids),
            'per_week'    => $perWeek,
            'sample'      => NoticeTransformer::collection($sample, 'free'),
        ], ['warning' => $warning]);
    }

    public function updateProfile(int $id)
    {
        $orgId   = (int) $this->request->orgId;
        $model   = model(AlertProfileModel::class);
        $profile = $model->where('org_id', $orgId)->find($id);

        if (! $profile) {
            return problem(404, 'not_found', 'No such profile.');
        }

        $in = $this->body();
        $csv = static fn ($v) => is_array($v) ? implode(',', $v) : (string) ($v ?? '');

        $updates = [];
        if (isset($in['name']) && trim((string) $in['name']) !== '') {
            $updates['name'] = trim((string) $in['name']);
        }
        if (isset($in['kinds'])) {
            $updates['kinds'] = $csv($in['kinds']) ?: 'tender';
        }
        if (isset($in['categories'])) {
            $updates['category_slugs'] = $csv($in['categories']);
        }
        if (isset($in['districts'])) {
            $updates['district_slugs'] = $csv($in['districts']);
        }
        if (isset($in['keywords'])) {
            $updates['keywords'] = $csv($in['keywords']);
        }
        if (array_key_exists('min_value', $in)) {
            $updates['min_value'] = $in['min_value'] !== null && $in['min_value'] !== '' ? (float) $in['min_value'] : null;
        }
        if (array_key_exists('max_value', $in)) {
            $updates['max_value'] = $in['max_value'] !== null && $in['max_value'] !== '' ? (float) $in['max_value'] : null;
        }
        if (isset($in['channels'])) {
            $updates['channels'] = $csv($in['channels']) ?: 'inapp';
        }
        if (array_key_exists('active', $in)) {
            $updates['active'] = !empty($in['active']) ? 1 : 0;
        }

        if (! empty($updates)) {
            $model->update($id, $updates);
            service('eventLedger')->record('alert_profile', $id, 'profile.updated', "Alert profile #{$id} updated", $updates);
        }

        return $this->ok($model->find($id));
    }

    public function deleteProfile(int $id)
    {
        $orgId   = (int) $this->request->orgId;
        $model   = model(AlertProfileModel::class);
        $profile = $model->where('org_id', $orgId)->find($id);

        if (! $profile) {
            return problem(404, 'not_found', 'No such profile.');
        }

        $model->delete($id);
        service('eventLedger')->record('alert_profile', $id, 'profile.deleted', "Alert profile #{$id} deleted", [
            'name' => $profile['name'],
        ]);

        return $this->ok(['id' => $id, 'deleted' => true]);
    }

    // ------------------------------------------------------- subscription
    public function subscription()
    {
        $org  = model('App\Models\OrganisationModel')->find((int) $this->request->orgId);
        $open = model('App\Models\PaymentModel')
            ->where('org_id', $org['id'])->where('state', 'claimed')->first();

        return $this->ok([
            'plan' => $org['plan'], 'status' => $org['sub_status'], 'renews_at' => $org['renews_at'],
            'open_claim' => $open ?: null,
        ], [
            'bank'  => config(Subscription::class)->bank,
            'terms' => config(Subscription::class)->terms,
        ]);
    }

    /** The claim form is not the subscription. It moves the account to pending
     *  and grants nothing. */
    public function claim()
    {
        $in      = $this->body();
        $orgId   = (int) $this->request->orgId;
        $payments= model('App\Models\PaymentModel');

        if ($payments->where('org_id', $orgId)->where('state', 'claimed')->first()) {
            return problem(409, 'claim_open', 'You already have a claim awaiting confirmation.');
        }

        $term  = ($in['term'] ?? 'annual') === 'quarterly' ? 'quarterly' : 'annual';
        $terms = config(Subscription::class)->terms;

        $id = $payments->insert([
            'org_id' => $orgId, 'user_id' => (int) $this->request->userId,
            'plan' => 'business', 'term' => $term,
            'amount' => $terms[$term]['amount'],
            'method' => 'bank_transfer',
            'bank' => $in['bank'] ?? null, 'slip_ref' => $in['slip_ref'] ?? null,
            'paid_on' => $in['paid_on'] ?? date('Y-m-d'),
            'channel' => $in['channel'] ?? 'email',
            'state' => 'claimed',
        ], true);

        model('App\Models\OrganisationModel')->update($orgId, ['sub_status' => 'pending']);

        return $this->ok($payments->find($id), [
            'note' => 'Your claim is with our team. Access starts when the transfer is confirmed.',
        ], 201);
    }
}
