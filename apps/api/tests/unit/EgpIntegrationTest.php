<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use App\Libraries\EGP\PromiseEgpAdapter;

/**
 * @internal
 */
final class EgpIntegrationTest extends CIUnitTestCase
{
    private PromiseEgpAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect();
        $this->adapter = new PromiseEgpAdapter();
    }

    public function testExternalLiveSyncFailsClosedWithoutLiveCredentials(): void
    {
        putenv('PROMISE_EGP_API_KEY=');
        unset($_ENV['PROMISE_EGP_API_KEY']);

        $adapter = new PromiseEgpAdapter();
        $this->assertFalse($adapter->hasLiveCredentials());

        $res = $adapter->syncLive();
        $this->assertFalse($res['ok']);
        $this->assertSame('EXTERNAL / NOT VERIFIED (PENDING LIVE NETWORK CREDENTIALS/FEEDS)', $res['status']);
    }

    public function testSchemaTransformationMapsMultilingualAndMetadata(): void
    {
        $rawEgpNotice = [
            'procurement_id'      => 'EGP/RDA/2026/089',
            'tender_title'        => 'Construction of Baseline Road Flyover Extension',
            'sinhala_title'       => 'මූලික මාර්ග ගුවන් පාලම දීර්ඝ කිරීමේ ඉදිකිරීම්',
            'tamil_title'         => 'பேஸ்லைன் வீதி மேம்பால விரிவாக்க கட்டுமானம்',
            'scope_of_work'       => '<p>Design and construction of 4-lane prestressed flyover.</p><script>alert("xss")</script>',
            'brief_summary'       => 'Baseline road flyover civil works.',
            'estimated_cost'      => 450000000.00,
            'currency'            => 'LKR',
            'submission_deadline' => '2026-10-31 14:00:00',
            'bid_opening_date'    => '2026-10-31 14:30:00',
            'location_district'   => 'Colombo',
            'procuring_entity'    => 'Road Development Authority',
            'portal_url'          => 'https://promise.lk/tenders/EGP-RDA-2026-089',
            'documents'           => [
                [
                    'name'         => 'Volume 1 - Bidding Documents.pdf',
                    'download_url' => 'https://promise.lk/docs/vol1.pdf',
                    'size'         => 2450000,
                ]
            ],
        ];

        $parsed = $this->adapter->transformEgpNotice($rawEgpNotice);

        $this->assertSame('EGP/RDA/2026/089', $parsed['reference']);
        $this->assertSame('Construction of Baseline Road Flyover Extension', $parsed['title']);
        $this->assertSame('මූලික මාර්ග ගුවන් පාලම දීර්ඝ කිරීමේ ඉදිකිරීම්', $parsed['title_si']);
        $this->assertSame('பேஸ்லைன் வீதி மேம்பால விரிவாக்க கட்டுமானம்', $parsed['title_ta']);
        $this->assertStringNotContainsString('<script>', $parsed['description']);
        $this->assertStringContainsString('4-lane prestressed flyover', $parsed['description']);
        $this->assertEquals(450000000.00, $parsed['estimated_value']);
        $this->assertNotNull($parsed['district_id']);
        $this->assertNotNull($parsed['category_id']);
        $this->assertCount(1, $parsed['raw_documents']);
    }

    public function testIngestBatchCreatesNoticeAndDocumentsAndAuditsLedger(): void
    {
        $uniqueRef = 'EGP-TEST-' . bin2hex(random_bytes(3));
        $uniqueTitle = 'Procurement of Solar PV Systems for Public Buildings ' . bin2hex(random_bytes(2));

        $batch = [
            [
                'procurement_id'      => $uniqueRef,
                'tender_title'        => $uniqueTitle,
                'sinhala_title'       => 'රාජ්‍ය ගොඩනැගිලි සඳහා සූර්ය පද්ධති ප්‍රසම්පාදනය',
                'scope_of_work'       => '<p>Supply, installation and commissioning of 500kW rooftop solar.</p>',
                'estimated_cost'      => 80000000.00,
                'submission_deadline' => date('Y-m-d H:i:s', time() + 86400 * 30),
                'location_district'   => 'Colombo',
                'documents'           => [
                    [
                        'name'         => 'Technical_Specifications_Solar.pdf',
                        'download_url' => 'https://promise.lk/files/specs.pdf',
                        'size'         => 1500000,
                    ]
                ]
            ]
        ];

        $res = $this->adapter->ingestBatch($batch);

        $this->assertSame(1, $res['items_found']);
        $this->assertSame(1, $res['items_inserted']);
        $this->assertSame(0, $res['items_skipped']);
        $this->assertSame(1, $res['docs_attached']);
        $this->assertSame('success', $res['status']);

        // Verify notice in database
        $notice = $this->db->table('notices')->where('reference', $uniqueRef)->get()->getFirstRow('array');
        $this->assertNotNull($notice);
        $this->assertSame(1, (int) $notice['verified']); // Official e-GP notice is pre-verified
        $this->assertSame('රාජ්‍ය ගොඩනැගිලි සඳහා සූර්ය පද්ධති ප්‍රසම්පාදනය', $notice['title_si']);

        // Verify document attached
        $doc = $this->db->table('notice_documents')->where('notice_id', (int) $notice['id'])->get()->getFirstRow('array');
        $this->assertNotNull($doc);
        $this->assertSame('Technical_Specifications_Solar.pdf', $doc['name']);
        $this->assertSame('https://promise.lk/files/specs.pdf', $doc['source_url']);

        // Verify Event Ledger
        $event = $this->db->table('event_ledger')
            ->where('entity_type', 'egp')
            ->where('event_type', 'egp.synced')
            ->orderBy('id', 'DESC')
            ->get()->getFirstRow('array');
        $this->assertNotNull($event);
        $this->assertStringContainsString('PROMISe e-GP sync completed', $event['summary']);
    }

    public function testIngestBatchDeduplicationSkipsIdenticalTenders(): void
    {
        $uniqueRef = 'EGP-DUP-' . bin2hex(random_bytes(3));
        $batch = [
            [
                'procurement_id'      => $uniqueRef,
                'tender_title'        => 'Routine Road Maintenance Works Southern Expressway',
                'submission_deadline' => date('Y-m-d H:i:s', time() + 86400 * 15),
            ]
        ];

        // Ingest first time
        $res1 = $this->adapter->ingestBatch($batch);
        $this->assertSame(1, $res1['items_inserted']);
        $this->assertSame(0, $res1['items_skipped']);

        // Ingest second time
        $res2 = $this->adapter->ingestBatch($batch);
        $this->assertSame(0, $res2['items_inserted']);
        $this->assertSame(1, $res2['items_skipped']);
    }
}
