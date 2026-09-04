<?php

namespace App\Controllers\Api\V1\PublicApi;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Jwt;
use App\Models\NoticeModel;
use App\Transformers\NoticeTransformer;

class NoticeController extends BaseApiController
{
    protected string $kind = '';

    protected function viewer(): ?array
    {
        $h = $this->request->getHeaderLine('Authorization');
        if (! str_starts_with($h, 'Bearer ')) {
            return null;
        }
        $claims = Jwt::parse(substr($h, 7));
        if (! $claims) {
            return null;
        }

        // Re-read entitlements server-side. A cookie alone never persuades the
        // API to release a paid payload.
        $org = model('App\Models\OrganisationModel')->find((int) ($claims['org'] ?? 0));

        return $org ? ['plan' => $org['plan'], 'sub_status' => $org['sub_status'], 'org' => (int) $org['id']] : null;
    }

    protected function filters(): array
    {
        return [
            'kind'       => $this->kind ?: ($this->request->getGet('kind') ?: ''),
            'q'          => trim((string) $this->request->getGet('q')),
            'categories' => $this->multi('category'),
            'districts'  => $this->multi('district'),
            'sectors'    => $this->multi('sector'),
            'bands'      => $this->multi('value_band'),
            'status'     => $this->request->getGet('status') ?: 'all',
        ];
    }

    protected function locale(): string
    {
        $loc = strtolower((string) ($this->request->getGet('locale') ?: $this->request->getGet('lang')));
        if (in_array($loc, ['si', 'ta', 'en'], true)) {
            return $loc;
        }

        $accept = strtolower($this->request->getHeaderLine('Accept-Language'));
        if (str_contains($accept, 'si')) {
            return 'si';
        }
        if (str_contains($accept, 'ta')) {
            return 'ta';
        }

        return 'en';
    }

    public function index()
    {
        $model  = model(NoticeModel::class);
        $f      = $this->filters();
        $tier   = NoticeTransformer::tierFor($this->viewer());
        $per    = $this->per();
        $page   = $this->page();
        $locale = $this->locale();

        $result = $model->search($f, $page, $per, (string) ($this->request->getGet('sort') ?: 'closing_at'));

        return $this->ok(
            NoticeTransformer::collection($result['rows'], $tier, [], $locale),
            [
                'page' => $page, 'per_page' => $per, 'total' => $result['total'],
                'pages' => (int) ceil($result['total'] / $per),
                'facets' => $model->facets($f),
                'status_counts' => $model->statusCounts($f),
                'value_bands' => NoticeModel::VALUE_BANDS,
                'tier' => $tier,
                'locale' => $locale,
            ]
        );
    }

    public function show(string $slug)
    {
        $model  = model(NoticeModel::class);
        $notice = $model->bySlug($slug) ?? (is_numeric($slug) ? $model->byId((int) $slug) : null);

        if (! $notice || $notice['status'] !== 'published') {
            return problem(404, 'not_found', 'No such notice.');
        }

        if ($notice['canonical_id']) {
            $canonical = $model->find((int) $notice['canonical_id']);

            return problem(301, 'merged', 'This notice was merged into another.', [
                'canonical_slug' => $canonical['slug'] ?? null,
            ]);
        }

        $viewer = $this->viewer();
        $tier   = NoticeTransformer::tierFor($viewer);

        $extra = [];
        if ($tier === 'paid') {
            $docs = model('App\Models\NoticeDocumentModel')
                ->where('notice_id', $notice['id'])->findAll();
            $extra['documents'] = array_map(static fn ($d) => [
                'id'        => (int) $d['id'],
                'name'      => $d['name'],
                'kind'      => $d['kind'],
                'size_bytes'=> (int) $d['size_bytes'],
                'sha256'    => $d['sha256'],
                // A document we know about but have not mirrored says so,
                // rather than offering a signed link that would 404.
                'available' => (bool) $d['mirrored_at'],
                'reason'    => $d['mirrored_at'] ? null : ($d['mirror_error'] ?: 'not_mirrored'),
                'source_url'=> $d['source_url'],
            ], $docs);
        }

        if ($notice['kind'] === 'auction') {
            $lot = $this->db()->table('auction_lots')->where('notice_id', $notice['id'])->get()->getFirstRow('array');
            if ($lot) {
                $extra['auction'] = $this->lot($lot);
            }
        }

        $locale = $this->locale();

        return $this->ok(NoticeTransformer::one($notice, $tier, $extra, $locale), ['tier' => $tier, 'locale' => $locale]);
    }

    protected function lot(array $lot): array
    {
        return [
            'lot_no'      => $lot['lot_no'],
            'asset_class' => $lot['asset_class'],
            'method'      => $lot['method'],
            'reserve'     => $lot['reserve'] !== null ? (float) $lot['reserve'] : null,
            'deposit_pct' => (float) $lot['deposit_pct'],
            // Computed, never stored twice: the figure on the notice and the
            // figure a bidder is asked for cannot disagree.
            'deposit'     => $lot['reserve'] !== null
                ? round((float) $lot['reserve'] * (float) $lot['deposit_pct'] / 100, 2) : null,
            'venue'       => $lot['venue'],
            'auctioneer'  => $lot['auctioneer'],
            'result'      => $lot['result'],
            'hammer_price'=> $lot['hammer_price'] !== null ? (float) $lot['hammer_price'] : null,
            'custody_note'=> 'TenderHub never holds any part of a purchase price. '
                . 'Deposits settle to the auctioneer\'s own account.',
        ];
    }

    protected function db()
    {
        return \Config\Database::connect();
    }
}
