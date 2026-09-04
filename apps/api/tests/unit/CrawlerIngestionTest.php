<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use App\Libraries\Ingestion\CrawlerIngestionService;
use App\Libraries\Ingestion\DeduplicationService;

/**
 * @internal
 */
final class CrawlerIngestionTest extends CIUnitTestCase
{
    private CrawlerIngestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect();
        $this->service = new CrawlerIngestionService();
    }

    public function testSsrfValidationBlocksForbiddenProtocols(): void
    {
        $fileRes = CrawlerIngestionService::validateFetchUrl('file:///etc/passwd');
        $this->assertFalse($fileRes['ok']);
        $this->assertStringContainsString('protocols', $fileRes['reason']);

        $ftpRes = CrawlerIngestionService::validateFetchUrl('ftp://ftp.example.com/dump');
        $this->assertFalse($ftpRes['ok']);

        $gopherRes = CrawlerIngestionService::validateFetchUrl('gopher://127.0.0.1:70');
        $this->assertFalse($gopherRes['ok']);
    }

    public function testSsrfValidationBlocksPrivateAndLoopbackIps(): void
    {
        $loopback = CrawlerIngestionService::validateFetchUrl('http://127.0.0.1/admin', true);
        $this->assertFalse($loopback['ok']);

        $localHost = CrawlerIngestionService::validateFetchUrl('http://localhost/secret', true);
        $this->assertFalse($localHost['ok']);

        $awsMeta = CrawlerIngestionService::validateFetchUrl('http://169.254.169.254/latest/meta-data', true);
        $this->assertFalse($awsMeta['ok']);

        $priv10 = CrawlerIngestionService::validateFetchUrl('http://10.0.0.5/internal', true);
        $this->assertFalse($priv10['ok']);

        $priv192 = CrawlerIngestionService::validateFetchUrl('http://192.168.1.100/router', true);
        $this->assertFalse($priv192['ok']);
    }

    public function testHtmlSanitizerStripsDangerousTagsAndHandlers(): void
    {
        $dirty = '<p>Procurement for supply of medical equipment.</p>' .
                 '<script>alert("XSS")</script>' .
                 '<iframe src="https://attacker.com/steal"></iframe>' .
                 '<a href="javascript:stealCookie()" onclick="bad()">Click me</a>' .
                 '<img src="x" onerror="alert(1)">' .
                 '<b>Crucial details</b>';

        $clean = CrawlerIngestionService::sanitizeHtml($dirty);

        $this->assertStringNotContainsString('<script>', $clean);
        $this->assertStringNotContainsString('alert("XSS")', $clean);
        $this->assertStringNotContainsString('<iframe>', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);

        $this->assertStringContainsString('Procurement for supply of medical equipment.', $clean);
        $this->assertStringContainsString('<b>Crucial details</b>', $clean);
    }

    public function testIngestItemsSavesNoticesAndTracksRun(): void
    {
        $title = 'Supply of Heavy Excavators for Irrigation Project ' . bin2hex(random_bytes(3));
        $items = [
            [
                'title'           => $title,
                'reference'       => 'GAZ-IRR-' . rand(1000, 9999),
                'description'     => '<p>Tenders called for hydraulic excavators.</p><script>evil()</script>',
                'sector'          => 'construction',
                'estimated_value' => 75000000.00,
                'closing_at'      => date('Y-m-d H:i:s', time() + 86400 * 30),
            ]
        ];

        $res = $this->service->ingestItems($items, null, 'push');

        $this->assertEquals(1, $res['items_found']);
        $this->assertEquals(1, $res['items_inserted']);
        $this->assertEquals(0, $res['items_skipped']);
        $this->assertEquals('success', $res['status']);
        $this->assertGreaterThan(0, $res['run_id']);

        // Verify ingestion_runs record
        $run = $this->db->table('ingestion_runs')->where('id', $res['run_id'])->get()->getFirstRow('array');
        $this->assertNotNull($run);
        $this->assertEquals(1, (int) $run['items_inserted']);
        $this->assertEquals('success', $run['status']);

        // Verify notice record
        $notice = $this->db->table('notices')->where('title', $title)->get()->getFirstRow('array');
        $this->assertNotNull($notice);
        $this->assertEquals('tender', $notice['kind']);
        $this->assertStringNotContainsString('evil()', $notice['description']);
        $this->assertStringContainsString('hydraulic excavators', $notice['description']);
        $this->assertEquals(0, (int) $notice['verified']); // Unverified gazette ingest

        // Verify Event Ledger entry
        $event = $this->db->table('event_ledger')
            ->where('entity_type', 'source')
            ->where('event_type', 'ingest.completed')
            ->orderBy('id', 'DESC')
            ->get()->getFirstRow('array');
        $this->assertNotNull($event);
        $this->assertStringContainsString('Ingested 1 notices', $event['summary']);
    }

    public function testIngestionDeduplicationSkipsDuplicateTenders(): void
    {
        $title = 'Rehabilitation of Bridge over Mahaweli River ' . bin2hex(random_bytes(3));
        $ref = 'GAZ-BRG-' . rand(1000, 9999);
        $closing = date('Y-m-d H:i:s', time() + 86400 * 45);

        $items = [
            [
                'title'       => $title,
                'reference'   => $ref,
                'description' => 'Bridge rehabilitation works tender.',
                'closing_at'  => $closing,
            ]
        ];

        // First ingest
        $first = $this->service->ingestItems($items, null, 'push');
        $this->assertEquals(1, $first['items_inserted']);
        $this->assertEquals(0, $first['items_skipped']);

        // Second ingest of exact same item
        $second = $this->service->ingestItems($items, null, 'push');
        $this->assertEquals(0, $second['items_inserted']);
        $this->assertEquals(1, $second['items_skipped']);

        // Verify ingestion run recorded the skip
        $run = $this->db->table('ingestion_runs')->where('id', $second['run_id'])->get()->getFirstRow('array');
        $this->assertEquals(1, (int) $run['items_skipped']);
    }

    public function testIngestFromSourceHandlesNotFoundAndFailure(): void
    {
        $res = $this->service->ingestFromSource(99999999);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('Source not found', $res['error']);
    }
}
