<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Ingestion\AutoCategoriser;
use App\Libraries\Ingestion\DeduplicationService;

class IngestWebhookController extends BaseApiController
{
    /**
     * POST /api/v1/admin/ingest/push
     * Ingestion crawler receiver webhook. Authenticated via X-Ingest-Key.
     */
    public function push()
    {
        $ingestKey   = trim($this->request->getHeaderLine('X-Ingest-Key'));
        $expectedKey = (string) (getenv('INGEST_SECRET_KEY') ?: '');

        if ($expectedKey === '' || $ingestKey === '' || ! hash_equals($expectedKey, $ingestKey)) {
            return problem(401, 'invalid_ingest_key', 'Unauthorized ingestion agent.');
        }

        $items = $this->body();
        if (! is_array($items) || empty($items)) {
            return problem(422, 'empty_payload', 'Payload must contain a non-empty array of notice items.');
        }

        $inserted = 0;
        $skipped  = 0;
        $db = db_connect();

        foreach ($items as $item) {
            $title = trim($item['title'] ?? '');
            if (empty($title)) {
                $skipped++;
                continue;
            }

            $slug = url_title($title, '-', true) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            $hash = DeduplicationService::fingerprint($title, $item['ref_no'] ?? null, $item['closing_at'] ?? null);

            if (DeduplicationService::isDuplicate($slug, $hash)) {
                $skipped++;
                continue;
            }

            $category = $item['category_id'] ?? AutoCategoriser::classify($title, $item['description'] ?? '');

            $db->table('notices')->insert([
                'slug'           => $slug,
                'title'          => $title,
                'description'    => $item['description'] ?? '',
                'category_id'    => $category,
                'buyer_name'     => $item['buyer_name'] ?? 'Government Ministry / Department',
                'ref_no'         => $item['ref_no'] ?? null,
                'source_hash'    => $hash,
                'source_name'    => $item['source'] ?? 'crawler_push',
                'closing_at'     => $item['closing_at'] ?? date('Y-m-d H:i:s', strtotime('+21 days')),
                'published_at'   => date('Y-m-d H:i:s'),
                'status'         => 'live',
                'stage_idx'      => 1, // Published stage
                'created_at'     => date('Y-m-d H:i:s'),
            ]);

            $inserted++;
        }

        return $this->ok([
            'processed' => count($items),
            'inserted'  => $inserted,
            'skipped'   => $skipped,
        ]);
    }
}
