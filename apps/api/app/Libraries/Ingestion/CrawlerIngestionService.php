<?php

namespace App\Libraries\Ingestion;

use RuntimeException;
use App\Libraries\EventLedger;

/**
 * Crawler and Gazette Ingestion Service
 *
 * Implements:
 * - Source configuration & safe fetching
 * - SSRF protection & IP range blocking
 * - Response size & timeout guards
 * - HTML sanitization & malformed markup protection
 * - Automatic categorization via AutoCategoriser
 * - Deduplication via DeduplicationService
 * - Source attribution & ingestion_runs metrics
 * - Event Ledger audit integration
 */
class CrawlerIngestionService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Validates that an outbound fetch target is safe from SSRF attacks.
     */
    public static function validateFetchUrl(string $url, bool $strict = false): array
    {
        $parsed = parse_url($url);
        if (! $parsed || empty($parsed['scheme'])) {
            return ['ok' => false, 'reason' => 'Invalid URL syntax.'];
        }

        $scheme = strtolower($parsed['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'reason' => 'Only HTTP and HTTPS protocols are permitted.'];
        }

        if (empty($parsed['host'])) {
            return ['ok' => false, 'reason' => 'URL must specify a valid host.'];
        }

        $isTesting = defined('ENVIRONMENT') && ENVIRONMENT === 'testing';
        $host = $parsed['host'];
        $ip = gethostbyname($host);

        if ($strict || ! $isTesting) {
            // Block private and reserved IP subnets (RFC 1918, RFC 3927 loopback, link-local, broadcast)
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return ['ok' => false, 'reason' => 'Target IP address belongs to a private or reserved network range.'];
            }
            if ($host === 'localhost' || $ip === '127.0.0.1' || $ip === '::1') {
                return ['ok' => false, 'reason' => 'Loopback fetching is prohibited.'];
            }
        }

        return ['ok' => true, 'ip' => $ip];
    }

    /**
     * Safely executes an HTTP GET request with SSRF checks, timeout, and size limits.
     */
    public function safeFetch(string $url, int $timeout = 10, int $maxBytes = 5242880): array
    {
        $val = self::validateFetchUrl($url);
        if (! $val['ok']) {
            return ['ok' => false, 'code' => 0, 'body' => '', 'error' => $val['reason']];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Prevent open redirect SSRF
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_MAXFILESIZE, $maxBytes);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TenderHub-Crawler/1.0 (+https://tenderhub.lk/crawler-policy)');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/json,text/xml;q=0.9,*/*;q=0.8',
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code >= 400 || $code === 0) {
            return [
                'ok'    => false,
                'code'  => $code,
                'body'  => '',
                'error' => $err ?: "HTTP request failed with status {$code}",
            ];
        }

        if (strlen($body) > $maxBytes) {
            return [
                'ok'    => false,
                'code'  => $code,
                'body'  => '',
                'error' => 'Response size exceeded maximum allowable threshold.',
            ];
        }

        return [
            'ok'    => true,
            'code'  => $code,
            'body'  => (string) $body,
            'error' => null,
        ];
    }

    /**
     * Strips dangerous script tags and event handlers from crawled markup.
     */
    public static function sanitizeHtml(string $html): string
    {
        // Remove script, iframe, object, embed, applet, style tags and their contents
        $clean = preg_replace('#<(script|iframe|object|embed|applet|style)[^>]*>.*?</\1>#is', '', $html);

        // Remove inline event handlers (e.g. onload, onerror, onclick)
        $clean = preg_replace('#\s+(on[a-z]+)\s*=\s*(["\'][^"\']*["\']|[^\s>]+)#i', '', $clean);

        // Remove javascript: URLs
        $clean = preg_replace('#href\s*=\s*["\']\s*javascript:[^"\']*["\']#i', 'href="#"', $clean);

        return trim(strip_tags($clean, '<p><br><b><strong><i><em><ul><ol><li><table><tr><td><th><thead><tbody>'));
    }

    /**
     * Ingests an array of notice items (push or pulled) into the database.
     */
    public function ingestItems(array $items, ?int $sourceId = null, string $mode = 'push'): array
    {
        $startTime = microtime(true);

        // Create ingestion run record
        $this->db->table('ingestion_runs')->insert([
            'source_id'      => $sourceId,
            'mode'           => $mode,
            'status'         => 'running',
            'items_found'    => count($items),
            'items_inserted' => 0,
            'items_skipped'  => 0,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        $runId = (int) $this->db->insertID();

        $inserted = 0;
        $skipped  = 0;

        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                $skipped++;
                continue;
            }

            // Sanitize description
            $desc = self::sanitizeHtml((string) ($item['description'] ?? ''));
            $ref  = !empty($item['reference']) ? trim((string) $item['reference']) : (!empty($item['ref_no']) ? trim((string) $item['ref_no']) : null);
            $closing = !empty($item['closing_at']) ? trim((string) $item['closing_at']) : date('Y-m-d H:i:s', time() + 86400 * 21);

            $slug = url_title($title, '-', true) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            $fingerprint = DeduplicationService::fingerprint($title, $ref, $closing);

            // Check duplicate
            if (DeduplicationService::isDuplicate($slug, $fingerprint)) {
                $skipped++;
                continue;
            }

            // Categorize
            $categoryName = AutoCategoriser::classify($title, $desc);
            $catRow = $this->db->table('categories')->like('slug', $categoryName)->get()->getFirstRow('array');
            $catId = $catRow ? (int) $catRow['id'] : null;

            // District matching if present
            $districtId = null;
            if (! empty($item['district'])) {
                $dRow = $this->db->table('districts')->like('name', trim($item['district']))->get()->getFirstRow('array');
                if ($dRow) {
                    $districtId = (int) $dRow['id'];
                }
            }

            // Estimate value
            $val = isset($item['estimated_value']) && is_numeric($item['estimated_value']) ? (float) $item['estimated_value'] : null;

            $this->db->table('notices')->insert([
                'kind'            => 'tender',
                'reference'       => $ref ?: ('GAZ-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6))),
                'slug'            => $slug,
                'title'           => mb_substr($title, 0, 255),
                'title_si'        => !empty($item['title_si']) ? mb_substr(trim($item['title_si']), 0, 255) : null,
                'title_ta'        => !empty($item['title_ta']) ? mb_substr(trim($item['title_ta']), 0, 255) : null,
                'summary'         => !empty($item['summary']) ? mb_substr(trim($item['summary']), 0, 500) : mb_substr($desc, 0, 200),
                'description'     => $desc,
                'source_id'       => $sourceId,
                'source_hash'     => $fingerprint,
                'source_url'      => !empty($item['source_url']) ? mb_substr(trim($item['source_url']), 0, 500) : null,
                'category_id'     => $catId,
                'district_id'     => $districtId,
                'sector'          => $item['sector'] ?? 'government',
                'estimated_value' => $val,
                'currency'        => $item['currency'] ?? 'LKR',
                'closing_at'      => $closing,
                'published_at'    => date('Y-m-d H:i:s'),
                'status'          => 'published',
                'verified'        => 0, // Unverified gazette crawler notice until audited
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

            $inserted++;
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $status = ($inserted > 0 || count($items) === 0) ? 'success' : ($skipped > 0 ? 'partial' : 'failed');

        // Update run metrics
        $this->db->table('ingestion_runs')->where('id', $runId)->update([
            'status'         => $status,
            'items_inserted' => $inserted,
            'items_skipped'  => $skipped,
            'duration_ms'    => $durationMs,
        ]);

        if ($sourceId) {
            $this->db->table('feed_sources')->where('id', $sourceId)->update([
                'last_fetch_at' => date('Y-m-d H:i:s'),
                'last_error'    => null,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        }

        // Audit Event
        service('eventLedger')->record('source', $sourceId ?: 0, 'ingest.completed', "Ingested {$inserted} notices (skipped {$skipped})", [
            'run_id'         => $runId,
            'source_id'      => $sourceId,
            'items_found'    => count($items),
            'items_inserted' => $inserted,
            'items_skipped'  => $skipped,
            'duration_ms'    => $durationMs,
        ]);

        return [
            'run_id'         => $runId,
            'status'         => $status,
            'items_found'    => count($items),
            'items_inserted' => $inserted,
            'items_skipped'  => $skipped,
            'duration_ms'    => $durationMs,
        ];
    }

    /**
     * Executes crawler ingestion from an active feed_source entry.
     */
    public function ingestFromSource(int $sourceId): array
    {
        $source = $this->db->table('feed_sources')->where('id', $sourceId)->get()->getFirstRow('array');
        if (! $source) {
            return ['ok' => false, 'error' => 'Source not found.'];
        }

        if (empty($source['url'])) {
            return ['ok' => false, 'error' => 'Source has no configured URL.'];
        }

        $fetch = $this->safeFetch($source['url']);
        if (! $fetch['ok']) {
            // Update source error
            $this->db->table('feed_sources')->where('id', $sourceId)->update([
                'last_fetch_at' => date('Y-m-d H:i:s'),
                'last_error'    => $fetch['error'],
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

            // Log failed ingestion run
            $this->db->table('ingestion_runs')->insert([
                'source_id'     => $sourceId,
                'mode'          => $source['mode'],
                'status'        => 'failed',
                'error_message' => $fetch['error'],
                'created_at'    => date('Y-m-d H:i:s'),
            ]);

            service('eventLedger')->record('source', $sourceId, 'ingest.failed', "Ingestion failed: {$fetch['error']}", [
                'source_id' => $sourceId,
                'error'     => $fetch['error'],
            ]);

            return ['ok' => false, 'error' => $fetch['error']];
        }

        // Try parsing JSON or standard structure
        $items = json_decode($fetch['body'], true);
        if (! is_array($items)) {
            $items = [];
        }

        $result = $this->ingestItems($items, $sourceId, $source['mode']);
        return array_merge(['ok' => true], $result);
    }
}
