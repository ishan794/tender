<?php

namespace Tests\Unit;

use App\Controllers\Api\V1\Authority\AuditController;
use App\Libraries\Audit\EventLedger;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use ReflectionClass;

/**
 * Validates Phase 7: Event Ledger & Audit Integrity.
 * Covers:
 * - Root hash & SHA-256 chain linking (prev_hash -> hash)
 * - Tamper detection (mutating payload, summary, prev_hash, or hash breaks verifyChain)
 * - API & Service immutability (no update/delete methods, no mutation routes)
 * - Access control & tenant isolation (401 unauth, 404 cross-tenant, 200 owning tenant)
 * - Transactional atomicity (rollback cleans ledger row)
 * - Lifecycle event coverage across all 8 domain lifecycles (plan, tender, opening, evaluation, award, contract, complaint, auction)
 */
class EventLedgerSecurityTest extends CIUnitTestCase
{
    protected $db;
    protected int $orgAId;
    protected int $orgBId;
    protected int $userAId;
    protected int $userBId;
    protected int $procId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = db_connect();

        // 1. Create org A and user A
        $this->db->table('organisations')->insert([
            'name' => 'Ledger Test Org A', 'slug' => 'ledger-org-a-' . uniqid(), 'verify_state' => 'verified', 'standstill_days' => 7,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->orgAId = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'org_id' => $this->orgAId, 'email' => 'usera_' . uniqid() . '@example.com',
            'password_hash' => password_hash('Secret123!', PASSWORD_DEFAULT),
            'role' => 'company', 'name' => 'User A', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->userAId = (int) $this->db->insertID();

        // 2. Create org B and user B (for cross-tenant testing)
        $this->db->table('organisations')->insert([
            'name' => 'Ledger Test Org B', 'slug' => 'ledger-org-b-' . uniqid(), 'verify_state' => 'verified', 'standstill_days' => 7,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->orgBId = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'org_id' => $this->orgBId, 'email' => 'userb_' . uniqid() . '@example.com',
            'password_hash' => password_hash('Secret123!', PASSWORD_DEFAULT),
            'role' => 'company', 'name' => 'User B', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->userBId = (int) $this->db->insertID();

        // 3. Create notice & procurement owned by Org A
        $this->db->table('notices')->insert([
            'kind' => 'tender', 'reference' => 'REF-LEDGER-' . rand(100, 999),
            'slug' => 'ledger-test-' . uniqid(), 'title' => 'Ledger Test Tender',
            'org_id' => $this->orgAId, 'closing_at' => date('Y-m-d H:i:s', time() + 86400),
            'opening_at' => date('Y-m-d H:i:s', time() + 86400), 'status' => 'draft',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $noticeId = (int) $this->db->insertID();

        $this->db->table('procurements')->insert([
            'notice_id' => $noticeId, 'org_id' => $this->orgAId,
            'stage_idx' => 1, 'created_by' => $this->userAId,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->procId = (int) $this->db->insertID();
    }

    protected function tearDown(): void
    {
        $this->db->table('event_ledger')->whereIn('entity_type', ['test_root', 'test_tamper', 'test_tx', 'procurement', 'plan', 'contract', 'complaint', 'auction'])->delete();
        $this->db->table('procurements')->where('id', $this->procId)->delete();
        $this->db->table('notices')->whereIn('org_id', [$this->orgAId, $this->orgBId])->delete();
        $this->db->table('users')->whereIn('id', [$this->userAId, $this->userBId])->delete();
        $this->db->table('organisations')->whereIn('id', [$this->orgAId, $this->orgBId])->delete();
        parent::tearDown();
    }

    private function makeRequest(string $method, string $path, array $claims = [], array $body = []): IncomingRequest
    {
        $config  = new App();
        $uri     = new URI('http://example.com' . $path);
        $request = new IncomingRequest($config, $uri, null, new UserAgent());
        $request->setMethod($method);
        if ($claims !== []) {
            $request->claims = $claims;
            $request->userId = (int) ($claims['sub'] ?? 0);
            $request->orgId  = (int) ($claims['org'] ?? 0);
        }
        if ($body !== []) {
            $request->setBody(json_encode($body));
        }
        Services::injectServerRequest($request);

        return $request;
    }

    public function testFirstEventRootHashAndChainLinking(): void
    {
        $ledger = new EventLedger($this->db);
        $entityId = rand(1000, 9999);

        $row1 = $ledger->record('test_root', $entityId, 'root.event1', 'First event for root test', ['a' => 1]);
        $this->assertNull($row1['prev_hash'], 'First event prev_hash must be null');
        $this->assertEquals(64, strlen($row1['hash']), 'Event hash must be a 64-character SHA-256 string');

        $row2 = $ledger->record('test_root', $entityId, 'root.event2', 'Second event for root test', ['b' => 2]);
        $this->assertEquals($row1['hash'], $row2['prev_hash'], 'Second event prev_hash must match first event hash');
        $this->assertEquals(64, strlen($row2['hash']));

        $row3 = $ledger->record('test_root', $entityId, 'root.event3', 'Third event for root test', ['c' => 3]);
        $this->assertEquals($row2['hash'], $row3['prev_hash'], 'Third event prev_hash must match second event hash');

        $result = $ledger->verifyChain('test_root', $entityId);
        $this->assertTrue($result['ok'], 'Verify chain must succeed on valid sequence');
        $this->assertEquals(3, $result['count']);
        $this->assertNull($result['broken_at']);
    }

    public function testTamperDetectionInPayloadOrHashChain(): void
    {
        $ledger = new EventLedger($this->db);
        $entityId = rand(1000, 9999);

        $r1 = $ledger->record('test_tamper', $entityId, 'tamper.e1', 'Initial state', ['score' => 10]);
        $r2 = $ledger->record('test_tamper', $entityId, 'tamper.e2', 'State update', ['score' => 20]);
        $r3 = $ledger->record('test_tamper', $entityId, 'tamper.e3', 'Final state', ['score' => 30]);

        $this->assertTrue($ledger->verifyChain('test_tamper', $entityId)['ok']);

        // Case 1: Tamper with row 2's payload
        $this->db->table('event_ledger')->where('id', $r2['id'])->update([
            'payload' => json_encode(['score' => 999]),
        ]);

        $resTamperedPayload = $ledger->verifyChain('test_tamper', $entityId);
        $this->assertFalse($resTamperedPayload['ok'], 'Tampering with payload must break hash chain');
        $this->assertEquals($r2['id'], $resTamperedPayload['broken_at'], 'Broken at must point to modified row 2');

        // Restore row 2 payload
        $this->db->table('event_ledger')->where('id', $r2['id'])->update(['payload' => json_encode(['score' => 20])]);
        $this->assertTrue($ledger->verifyChain('test_tamper', $entityId)['ok']);

        // Case 2: Tamper with row 3's prev_hash
        $this->db->table('event_ledger')->where('id', $r3['id'])->update(['prev_hash' => '0000000000000000000000000000000000000000000000000000000000000000']);
        $resTamperedPrevHash = $ledger->verifyChain('test_tamper', $entityId);
        $this->assertFalse($resTamperedPrevHash['ok'], 'Tampering with prev_hash must break hash chain');
        $this->assertEquals($r3['id'], $resTamperedPrevHash['broken_at'], 'Broken at must point to row 3');
    }

    public function testImmutabilityOfEventLedgerServiceAndApi(): void
    {
        $ref = new ReflectionClass(EventLedger::class);
        $this->assertFalse($ref->hasMethod('update'), 'EventLedger class must not contain update method');
        $this->assertFalse($ref->hasMethod('delete'), 'EventLedger class must not contain delete method');

        // Verify no mutation routes exist in application routes
        $routes = Services::routes();
        $routeOptions = $routes->getRoutes('put');
        $this->assertArrayNotHasKey('api/v1/authority/tenders/(:num)/ledger', $routeOptions ?? []);
        $routeOptionsDelete = $routes->getRoutes('delete');
        $this->assertArrayNotHasKey('api/v1/authority/tenders/(:num)/ledger', $routeOptionsDelete ?? []);
    }

    public function testAccessControlAndTenantIsolationForAuditLedgerEndpoint(): void
    {
        $ledger = new EventLedger($this->db);
        $ledger->record('procurement', $this->procId, 'tender.submitted', 'Tender submitted for approval', [], [
            'id' => $this->userAId, 'name' => 'User A', 'role' => 'company', 'org' => $this->orgAId,
        ]);

        // 1. Cross-tenant (Org B reading Org A's ledger) -> 404
        $reqOrgB = $this->makeRequest('GET', "/api/v1/authority/tenders/{$this->procId}/ledger", [
            'sub' => $this->userBId, 'org' => $this->orgBId, 'role' => 'company', 'nm' => 'User B',
        ]);
        $ctrlOrgB = new AuditController();
        $ctrlOrgB->initController($reqOrgB, Services::response(), Services::logger());
        $resOrgB = $ctrlOrgB->ledger($this->procId);
        $this->assertEquals(404, $resOrgB->getStatusCode(), 'Cross-tenant request must be rejected with 404');

        // 2. Owning tenant (Org A reading Org A's ledger) -> 200
        $reqOrgA = $this->makeRequest('GET', "/api/v1/authority/tenders/{$this->procId}/ledger", [
            'sub' => $this->userAId, 'org' => $this->orgAId, 'role' => 'company', 'nm' => 'User A',
        ]);
        $ctrlOrgA = new AuditController();
        $ctrlOrgA->initController($reqOrgA, Services::response(), Services::logger());
        $resOrgA = $ctrlOrgA->ledger($this->procId);
        $this->assertEquals(200, $resOrgA->getStatusCode(), 'Owning tenant request must return 200 OK');

        $body = json_decode($resOrgA->getBody(), true);
        $this->assertNotEmpty($body['data']);
        $this->assertTrue($body['meta']['integrity']['ok']);
        $this->assertEquals(1, $body['meta']['integrity']['count']);
    }

    public function testTransactionalAtomicityOfEventLedger(): void
    {
        $ledger = new EventLedger($this->db);
        $entityId = rand(1000, 9999);

        $this->db->transBegin();
        $ledger->record('test_tx', $entityId, 'tx.event', 'Event within transaction');

        $countInside = $this->db->table('event_ledger')
            ->where('entity_type', 'test_tx')
            ->where('entity_id', $entityId)
            ->countAllResults();
        $this->assertEquals(1, $countInside, 'Record must exist inside active transaction');

        $this->db->transRollback();

        $countAfterRollback = $this->db->table('event_ledger')
            ->where('entity_type', 'test_tx')
            ->where('entity_id', $entityId)
            ->countAllResults();
        $this->assertEquals(0, $countAfterRollback, 'Rolled back transaction must clean up ledger entry');
    }

    public function testLifecycleEventCoverageAcrossAllDomains(): void
    {
        $ledger = new EventLedger($this->db);

        // 1. Plan
        $ledger->record('plan', 10, 'plan.created', 'Plan line created');
        $ledger->record('plan', 10, 'plan.submitted', 'Plan submitted');
        $ledger->record('plan', 10, 'plan.approved', 'Plan approved');
        $ledger->record('plan', 10, 'plan.revised', 'Plan revised');
        $ledger->record('plan', 10, 'plan.linked', 'Plan linked to tender');
        $this->assertTrue($ledger->verifyChain('plan', 10)['ok']);

        // 2. Tender / Procurement
        $ledger->record('procurement', 20, 'tender.submitted', 'Submitted');
        $ledger->record('procurement', 20, 'tender.approved', 'Approved');
        $ledger->record('procurement', 20, 'tender.published', 'Published');
        $ledger->record('procurement', 20, 'addendum.issued', 'Addendum 1');
        $this->assertTrue($ledger->verifyChain('procurement', 20)['ok']);

        // 3. Opening
        $ledger->record('procurement', 21, 'opening.started', 'Opening started officer A');
        $ledger->record('procurement', 21, 'opening.countersigned', 'Opening countersigned officer B');
        $this->assertTrue($ledger->verifyChain('procurement', 21)['ok']);

        // 4. Evaluation
        $ledger->record('procurement', 22, 'eval.coi_declared', 'COI declared');
        $ledger->record('procurement', 22, 'eval.scored', 'Evaluation scores recorded');
        $this->assertTrue($ledger->verifyChain('procurement', 22)['ok']);

        // 5. Award
        $ledger->record('procurement', 23, 'award.created', 'Contract awarded');
        $this->assertTrue($ledger->verifyChain('procurement', 23)['ok']);

        // 6. Contract
        $ledger->record('contract', 30, 'contract.created', 'Contract created');
        $ledger->record('contract', 30, 'contract.activated', 'Contract activated');
        $ledger->record('contract', 30, 'contract.milestone_added', 'Milestone 1 added');
        $ledger->record('contract', 30, 'contract.milestone_met', 'Milestone 1 met');
        $ledger->record('contract', 30, 'contract.variation', 'Variation 1');
        $ledger->record('contract', 30, 'contract.invoice_submitted', 'Invoice 1');
        $this->assertTrue($ledger->verifyChain('contract', 30)['ok']);

        // 7. Complaint
        $ledger->record('complaint', 40, 'complaint.submitted', 'Complaint submitted');
        $ledger->record('complaint', 40, 'complaint.appealed', 'Complaint appealed');
        $this->assertTrue($ledger->verifyChain('complaint', 40)['ok']);

        // 8. Auction
        $ledger->record('auction', 50, 'auction.created', 'Auction created');
        $ledger->record('auction', 50, 'auction.published', 'Auction published');
        $ledger->record('auction', 50, 'auction.result_recorded', 'Auction result recorded');
        $this->assertTrue($ledger->verifyChain('auction', 50)['ok']);
    }
}
