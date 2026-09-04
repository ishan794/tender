<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use App\Libraries\Webhooks\WebhookDispatcher;

class WebhookDeliveryTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect();
    }

    public function testRegisterWebhookSucceedsAndEncryptsSecret(): void
    {
        $dispatcher = new WebhookDispatcher();
        $orgId = 1;
        $url = 'https://partner.example.com/webhooks/tenders';
        $event = 'notice.published';

        $res = $dispatcher->register($orgId, $url, $event);
        $this->assertTrue($res['ok']);
        $this->assertNotEmpty($res['signing_secret']);
        $this->assertSame(64, strlen($res['signing_secret'])); // 32 bytes hex
        $this->assertSame($url, $res['url']);

        $row = $this->db->table('webhooks')->where('id', $res['id'])->get()->getFirstRow('array');
        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', $res['signing_secret']), $row['secret_hash']);
        $this->assertNotEmpty($row['secret_ciphertext']);
        // Secret itself must not be stored in plaintext
        $this->assertNotEquals($res['signing_secret'], $row['secret_ciphertext']);

        // Clean up
        $dispatcher->deleteForOrg($orgId, $res['id']);
    }

    public function testRegisterWebhookRejectsUnknownEvent(): void
    {
        $dispatcher = new WebhookDispatcher();
        $res = $dispatcher->register(1, 'https://partner.example.com/webhook', 'arbitrary.malicious_event');
        $this->assertFalse($res['ok']);
        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('Unknown event', $res['error']);
    }

    public function testHmacSignatureGenerationAndConstantTimeVerification(): void
    {
        $payload = json_encode(['notice_id' => 101, 'reference' => 'REF-101', 'title' => 'Bridge Tender']);
        $secret = bin2hex(random_bytes(32));
        $timestamp = time();

        $header = WebhookDispatcher::signPayload($payload, $secret, $timestamp);
        $this->assertStringStartsWith("t={$timestamp},v1=", $header);

        // Verification with exact match
        $valid = WebhookDispatcher::verifySignature($payload, $header, $secret, 300);
        $this->assertTrue($valid, 'Valid signature must verify successfully.');

        // Tampered payload
        $tamperedPayload = json_encode(['notice_id' => 101, 'reference' => 'REF-101', 'title' => 'Bridge Tender Tampered']);
        $invalidPayload = WebhookDispatcher::verifySignature($tamperedPayload, $header, $secret, 300);
        $this->assertFalse($invalidPayload, 'Tampered payload must fail signature verification.');

        // Wrong secret
        $wrongSecret = bin2hex(random_bytes(32));
        $invalidSecret = WebhookDispatcher::verifySignature($payload, $header, $wrongSecret, 300);
        $this->assertFalse($invalidSecret, 'Wrong secret must fail signature verification.');
    }

    public function testReplayProtectionRejectsExpiredTimestamp(): void
    {
        $payload = json_encode(['event' => 'ping']);
        $secret = 'test_secret_for_replay_checks_12345';
        $oldTimestamp = time() - 600; // 10 minutes ago (exceeds 300s tolerance)

        $header = WebhookDispatcher::signPayload($payload, $secret, $oldTimestamp);
        $valid = WebhookDispatcher::verifySignature($payload, $header, $secret, 300);
        $this->assertFalse($valid, 'Signatures older than tolerance window must be rejected (replay protection).');
    }

    public function testDispatchGeneratesDeliveryRecordAndEnforcesIdempotency(): void
    {
        $dispatcher = new WebhookDispatcher();
        $orgId = 1;
        // Using a non-existent port to test delivery logging without making external network calls
        $res = $dispatcher->register($orgId, 'https://127.0.0.1:59999/dummy-webhook', 'notice.published');
        $this->assertTrue($res['ok']);
        $whId = $res['id'];

        $eventPayload = [
            'id'        => 555,
            'reference' => 'TND-555',
            'action'    => 'published',
            'timestamp' => date('c'),
        ];

        // 1. First dispatch (async mode so we inspect generated record)
        $dispatch1 = $dispatcher->dispatch('notice.published', $eventPayload, $orgId, false);
        $this->assertSame(1, $dispatch1['dispatched']);
        $deliveryId = $dispatch1['deliveries'][0]['delivery_id'];

        $delivery = $this->db->table('webhook_deliveries')->where('id', $deliveryId)->get()->getFirstRow('array');
        $this->assertNotNull($delivery);
        $this->assertSame('queued', $delivery['status']);
        $this->assertSame('notice.published', $delivery['event']);
        $this->assertNotEmpty($delivery['signature']);
        $this->assertNotEmpty($delivery['idempotency_key']);

        // Mark as delivered to test deduplication
        $this->db->table('webhook_deliveries')->where('id', $deliveryId)->update(['status' => 'delivered']);

        // 2. Second dispatch of identical payload within window should be suppressed as duplicate
        $dispatch2 = $dispatcher->dispatch('notice.published', $eventPayload, $orgId, false);
        $this->assertSame(1, $dispatch2['dispatched']);
        $this->assertSame('idempotent_duplicate', $dispatch2['deliveries'][0]['status']);

        // Clean up
        $dispatcher->deleteForOrg($orgId, $whId);
    }

    public function testTenantIsolationInWebhookManagement(): void
    {
        $dispatcher = new WebhookDispatcher();
        $orgA = 1;
        $orgB = 2;

        // Register for Org A
        $resA = $dispatcher->register($orgA, 'https://orga.example.com/hook', 'notice.published');
        $whIdA = $resA['id'];

        // Org B lists webhooks -> must NOT see Org A's webhook
        $listB = $dispatcher->listForOrg($orgB);
        $foundInB = array_filter($listB, fn($w) => (int)$w['id'] === $whIdA);
        $this->assertEmpty($foundInB, 'Org B must not see Org A webhooks.');

        // Org B tries to delete Org A's webhook -> must be rejected
        $deleted = $dispatcher->deleteForOrg($orgB, $whIdA);
        $this->assertFalse($deleted, 'Org B must not be able to delete Org A webhook.');

        // Org A can delete its own webhook
        $deletedOwn = $dispatcher->deleteForOrg($orgA, $whIdA);
        $this->assertTrue($deletedOwn, 'Org A must be able to delete its own webhook.');
    }
}
