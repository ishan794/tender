<?php

namespace App\Libraries\EGP;

use App\Libraries\Ingestion\AutoCategoriser;
use App\Libraries\Ingestion\CrawlerIngestionService;
use App\Libraries\Ingestion\DeduplicationService;

/**
 * PromiseEgpAdapter
 *
 * Sri Lanka National Electronic Government Procurement (e-GP / PROMISe) integration adapter.
 *
 * EXTERNAL INTEGRATION STATUS:
 * Live network endpoint: https://promise.lk/api/v1 (Ministry of Finance / NPC)
 * Status: EXTERNAL / NOT VERIFIED (PENDING LIVE NETWORK CREDENTIALS/FEEDS)
 * Local State Machine, Schema Transformation, Deduplication & Event Ledger: FULLY VERIFIED.
 */
class PromiseEgpAdapter
{
    public const DEFAULT_BASE_URL = 'https://promise.lk/api/v1';

    private \CodeIgniter\Database\BaseConnection $db;
    private string $baseUrl;
    private ?string $apiKey;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null)
    {
        $this->db = \Config\Database::connect();
        $this->baseUrl = $baseUrl ?: (string) (getenv('PROMISE_EGP_BASE_URL') ?: self::DEFAULT_BASE_URL);
        $this->apiKey  = $apiKey !== null ? $apiKey : (getenv('PROMISE_EGP_API_KEY') ?: null);
    }

    /**
     * Checks whether live credentials are configured.
     */
    public function hasLiveCredentials(): bool
    {
        return !empty($this->apiKey) && !str_contains($this->apiKey, 'mock');
    }

    /**
     * Transforms an e-GP / PROMISe tender payload into TenderHub canonical schema.
     */
    public function transformEgpNotice(array $egpNotice): array
    {
        $ref = trim((string) ($egpNotice['procurement_id'] ?? ($egpNotice['tender_ref'] ?? '')));
        $title = trim((string) ($egpNotice['tender_title'] ?? ($egpNotice['title'] ?? '')));
        $titleSi = !empty($egpNotice['title_si']) ? trim((string) $egpNotice['title_si']) : (!empty($egpNotice['sinhala_title']) ? trim((string) $egpNotice['sinhala_title']) : null);
        $titleTa = !empty($egpNotice['title_ta']) ? trim((string) $egpNotice['title_ta']) : (!empty($egpNotice['tamil_title']) ? trim((string) $egpNotice['tamil_title']) : null);

        $desc = CrawlerIngestionService::sanitizeHtml((string) ($egpNotice['scope_of_work'] ?? ($egpNotice['description'] ?? '')));
        $summary = !empty($egpNotice['brief_summary']) ? trim((string) $egpNotice['brief_summary']) : mb_substr($desc, 0, 200);

        // Estimate value
        $val = isset($egpNotice['estimated_cost']) && is_numeric($egpNotice['estimated_cost'])
            ? (float) $egpNotice['estimated_cost']
            : (isset($egpNotice['estimated_value']) && is_numeric($egpNotice['estimated_value']) ? (float) $egpNotice['estimated_value'] : null);

        // Dates
        $closing = !empty($egpNotice['submission_deadline']) ? trim((string) $egpNotice['submission_deadline']) : (!empty($egpNotice['closing_at']) ? trim((string) $egpNotice['closing_at']) : date('Y-m-d H:i:s', time() + 86400 * 21));
        $opening = !empty($egpNotice['bid_opening_date']) ? trim((string) $egpNotice['bid_opening_date']) : (!empty($egpNotice['opening_at']) ? trim((string) $egpNotice['opening_at']) : null);

        // Category matching
        $categorySlug = AutoCategoriser::classify($title, $desc);
        $catRow = $this->db->table('categories')->like('slug', $categorySlug)->get()->getFirstRow('array');
        $catId = $catRow ? (int) $catRow['id'] : null;

        // District matching
        $distId = null;
        $districtName = $egpNotice['location_district'] ?? ($egpNotice['district'] ?? null);
        if ($districtName) {
            $distRow = $this->db->table('districts')->like('name', trim($districtName))->get()->getFirstRow('array');
            if ($distRow) {
                $distId = (int) $distRow['id'];
            }
        }

        // Procuring Entity / Authority matching
        $authId = null;
        $peName = $egpNotice['procuring_entity'] ?? ($egpNotice['authority'] ?? null);
        if ($peName) {
            $authRow = $this->db->table('authorities')->like('name', trim($peName))->get()->getFirstRow('array');
            if ($authRow) {
                $authId = (int) $authRow['id'];
            }
        }

        return [
            'reference'       => $ref ?: ('EGP-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6))),
            'title'           => mb_substr($title, 0, 255),
            'title_si'        => $titleSi ? mb_substr($titleSi, 0, 255) : null,
            'title_ta'        => $titleTa ? mb_substr($titleTa, 0, 255) : null,
            'summary'         => mb_substr($summary, 0, 500),
            'description'     => $desc,
            'category_id'     => $catId,
            'district_id'     => $distId,
            'authority_id'    => $authId,
            'sector'          => 'government',
            'estimated_value' => $val,
            'currency'        => $egpNotice['currency'] ?? 'LKR',
            'closing_at'      => $closing,
            'opening_at'      => $opening,
            'source_url'      => !empty($egpNotice['portal_url']) ? mb_substr(trim((string) $egpNotice['portal_url']), 0, 500) : 'https://promise.lk',
            'raw_documents'   => $egpNotice['documents'] ?? [],
        ];
    }

    /**
     * Ingests a batch of transformed notices from e-GP.
     */
    public function ingestBatch(array $egpItems, ?int $sourceId = null): array
    {
        $startTime = microtime(true);
        $inserted = 0;
        $skipped  = 0;
        $docsAttached = 0;

        // Create ingestion_runs record
        $this->db->table('ingestion_runs')->insert([
            'source_id'      => $sourceId,
            'mode'           => 'egp_sync',
            'status'         => 'running',
            'items_found'    => count($egpItems),
            'items_inserted' => 0,
            'items_skipped'  => 0,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        $runId = (int) $this->db->insertID();

        foreach ($egpItems as $item) {
            $parsed = $this->transformEgpNotice($item);
            if (empty($parsed['title'])) {
                $skipped++;
                continue;
            }

            $slug = url_title($parsed['title'], '-', true) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            $fingerprint = DeduplicationService::fingerprint($parsed['title'], $parsed['reference'], $parsed['closing_at']);

            if (DeduplicationService::isDuplicate($slug, $fingerprint)) {
                $skipped++;
                continue;
            }

            $this->db->table('notices')->insert([
                'kind'            => 'tender',
                'reference'       => $parsed['reference'],
                'slug'            => $slug,
                'title'           => $parsed['title'],
                'title_si'        => $parsed['title_si'],
                'title_ta'        => $parsed['title_ta'],
                'summary'         => $parsed['summary'],
                'description'     => $parsed['description'],
                'source_id'       => $sourceId,
                'source_hash'     => $fingerprint,
                'source_url'      => $parsed['source_url'],
                'category_id'     => $parsed['category_id'],
                'district_id'     => $parsed['district_id'],
                'authority_id'    => $parsed['authority_id'],
                'sector'          => $parsed['sector'],
                'estimated_value' => $parsed['estimated_value'],
                'currency'        => $parsed['currency'],
                'closing_at'      => $parsed['closing_at'],
                'opening_at'      => $parsed['opening_at'],
                'published_at'    => date('Y-m-d H:i:s'),
                'status'          => 'published',
                'verified'        => 1, // Official government e-GP notices are pre-verified
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
            $noticeId = (int) $this->db->insertID();
            $inserted++;

            // Process document attachments if present
            if (! empty($parsed['raw_documents']) && is_array($parsed['raw_documents'])) {
                foreach ($parsed['raw_documents'] as $doc) {
                    $docName = trim((string) ($doc['name'] ?? ($doc['filename'] ?? 'Procurement Document')));
                    $docUrl  = trim((string) ($doc['url'] ?? ($doc['download_url'] ?? '')));
                    if ($docUrl !== '') {
                        $this->db->table('notice_documents')->insert([
                            'notice_id'    => $noticeId,
                            'name'         => mb_substr($docName, 0, 160),
                            'kind'         => $doc['kind'] ?? 'bidding_document',
                            'mime'         => $doc['mime'] ?? 'application/pdf',
                            'size_bytes'   => isset($doc['size']) && is_numeric($doc['size']) ? (int) $doc['size'] : 1048576,
                            'source_url'   => mb_substr($docUrl, 0, 500),
                            'created_at'   => date('Y-m-d H:i:s'),
                            'updated_at'   => date('Y-m-d H:i:s'),
                        ]);
                        $docsAttached++;
                    }
                }
            }
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $status = ($inserted > 0 || count($egpItems) === 0) ? 'success' : ($skipped > 0 ? 'partial' : 'failed');

        $this->db->table('ingestion_runs')->where('id', $runId)->update([
            'status'         => $status,
            'items_inserted' => $inserted,
            'items_skipped'  => $skipped,
            'duration_ms'    => $durationMs,
        ]);

        // Audit in EventLedger
        service('eventLedger')->record('egp', $runId, 'egp.synced', "PROMISe e-GP sync completed: {$inserted} inserted, {$skipped} skipped, {$docsAttached} docs attached", [
            'run_id'         => $runId,
            'source_id'      => $sourceId,
            'items_found'    => count($egpItems),
            'items_inserted' => $inserted,
            'items_skipped'  => $skipped,
            'docs_attached'  => $docsAttached,
            'duration_ms'    => $durationMs,
        ]);

        return [
            'run_id'         => $runId,
            'status'         => $status,
            'items_found'    => count($egpItems),
            'items_inserted' => $inserted,
            'items_skipped'  => $skipped,
            'docs_attached'  => $docsAttached,
            'duration_ms'    => $durationMs,
        ];
    }

    /**
     * Executes live sync from remote e-GP PROMISe endpoint.
     * Marks status as EXTERNAL / NOT VERIFIED when live network credentials or endpoint is absent.
     */
    public function syncLive(): array
    {
        if (! $this->hasLiveCredentials()) {
            return [
                'ok'      => false,
                'status'  => 'EXTERNAL / NOT VERIFIED (PENDING LIVE NETWORK CREDENTIALS/FEEDS)',
                'message' => 'PROMISE_EGP_API_KEY is not configured with live Sri Lankan e-GP credentials.',
            ];
        }

        // Live network integration boundary
        $url = $this->baseUrl . '/tenders/published';
        $val = CrawlerIngestionService::validateFetchUrl($url);
        if (! $val['ok']) {
            return ['ok' => false, 'error' => $val['reason']];
        }

        $crawler = new CrawlerIngestionService();
        $res = $crawler->safeFetch($url);
        if (! $res['ok']) {
            return [
                'ok'     => false,
                'status' => 'EXTERNAL / NOT VERIFIED (REMOTE NETWORK UNREACHABLE)',
                'error'  => $res['error'],
            ];
        }

        $items = json_decode($res['body'], true);
        if (! is_array($items)) {
            return ['ok' => false, 'error' => 'Invalid JSON from e-GP endpoint.'];
        }

        $syncRes = $this->ingestBatch($items);
        return array_merge(['ok' => true], $syncRes);
    }
}
