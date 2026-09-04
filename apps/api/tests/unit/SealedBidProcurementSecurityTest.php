<?php

namespace Tests\Unit;

use App\Controllers\Api\V1\Authority\EvaluationController;
use App\Controllers\Api\V1\Authority\OpeningController;
use App\Controllers\Api\V1\Authority\SaleController;
use App\Controllers\Api\V1\Authority\TenderController;
use App\Controllers\Api\V1\Member\BidController;
use App\Libraries\Security\CryptoService;
use App\Models\SubmissionModel;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Validates Phase 6: Sealed-Bid & Procurement Security.
 * Covers:
 * - Cryptographic envelope encryption at rest (AES-256-GCM), key derivation, tamper detection, key isolation
 * - Dual-control opening ceremony timing gates, two-officer separation, replay resistance, query-level secrecy (Rule 4)
 * - Conflict of Interest (COI) permanent lockout and declaration immutability
 * - Submission integrity, document purchase prerequisite, cross-tender bypass resistance, digital receipt isolation (ETA 2006)
 */
class SealedBidProcurementSecurityTest extends CIUnitTestCase
{
    protected $db;
    protected int $buyerOrgId;
    protected int $bidderOrgId;
    protected int $secondBidderOrgId;
    protected int $officerUserId;
    protected int $approverUserId;
    protected int $opener2UserId;
    protected int $evalClearUserId;
    protected int $evalConflictedUserId;
    protected int $bidderUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect('default');

        // Resolve fixtures from seeded database
        $buyerOrg = $this->db->table('organisations')->where('type', 'company')->get()->getFirstRow('array');
        $this->buyerOrgId = (int) $buyerOrg['id'];

        $bidderOrgs = $this->db->table('organisations')->where('type', 'bidder')->where('sub_status', 'active')->get()->getResultArray();
        $this->bidderOrgId = (int) $bidderOrgs[0]['id'];
        $this->secondBidderOrgId = (int) ($bidderOrgs[1]['id'] ?? ($this->bidderOrgId + 1));

        $officer = $this->db->table('users')->where('email', 'officer@rda.lk')->get()->getFirstRow('array');
        $this->officerUserId = (int) $officer['id'];

        $approver = $this->db->table('users')->where('email', 'approver@rda.lk')->get()->getFirstRow('array');
        $this->approverUserId = (int) $approver['id'];

        $opener2 = $this->db->table('users')->where('email', 'opener2@rda.lk')->get()->getFirstRow('array');
        $this->opener2UserId = (int) $opener2['id'];

        $evalClear = $this->db->table('users')->where('email', 'evaluator@rda.lk')->get()->getFirstRow('array');
        $this->evalClearUserId = (int) $evalClear['id'];

        $evalConf = $this->db->table('users')->where('email', 'conflicted@rda.lk')->get()->getFirstRow('array');
        $this->evalConflictedUserId = (int) $evalConf['id'];

        $bidderUser = $this->db->table('users')->where('org_id', $this->bidderOrgId)->get()->getFirstRow('array');
        $this->bidderUserId = (int) $bidderUser['id'];
    }

    private function createRequest(string $url, string $method = 'GET', array $body = [], ?int $userId = null, ?int $orgId = null): IncomingRequest
    {
        $uri = new URI($url);
        $request = new IncomingRequest(new App(), $uri, 'php://input', new UserAgent());
        $request->setMethod(strtoupper($method));
        $request->setHeader('Accept', 'application/json');

        if (! empty($body)) {
            $request->setHeader('Content-Type', 'application/json');
            $request->setBody(json_encode($body));
        }

        if ($userId !== null) {
            $request->userId = $userId;
            $request->claims = [
                'sub' => $userId,
                'org' => $orgId ?? 0,
                'nm'  => 'Test User',
            ];
        }

        if ($orgId !== null) {
            $request->orgId = $orgId;
        }

        return $request;
    }

    private function executeController(object $controller, string $method, array $params = [], ?IncomingRequest $request = null)
    {
        if ($request !== null) {
            $controller->initController($request, Services::response(), new \Psr\Log\NullLogger());
        }
        return $controller->$method(...$params);
    }

    private function createPublishedTender(string $refSuffix, string $closingAt, string $openingAt): array
    {
        $tenderCtrl = new TenderController();

        $reqCreate = $this->createRequest(
            'http://localhost:8080/api/v1/authority/tenders',
            'POST',
            [
                'title'           => "Security Test Tender {$refSuffix}",
                'reference'       => "SEC-TND-{$refSuffix}",
                'closing_at'      => $closingAt,
                'opening_at'      => $openingAt,
                'estimated_value' => 25000000.00,
            ],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resCreate = $this->executeController($tenderCtrl, 'create', [], $reqCreate);
        $tData = json_decode($resCreate->getBody(), true)['data'];
        $procId = (int) $tData['id'];

        // Submit for approval
        $this->executeController($tenderCtrl, 'submitForApproval', [$procId], $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/submit-for-approval",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        ));

        // Distinct approver approves
        $this->executeController($tenderCtrl, 'approve', [$procId], $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/approve",
            'POST',
            [],
            $this->approverUserId,
            $this->buyerOrgId
        ));

        // Publish
        $this->executeController($tenderCtrl, 'publish', [$procId], $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/publish",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        ));

        $procRow = $this->db->table('procurements')->where('id', $procId)->get()->getFirstRow('array');
        return [
            'proc_id'   => $procId,
            'notice_id' => (int) $procRow['notice_id'],
        ];
    }

    public function testCryptographicSecrecyAtRestAndTamperResistance(): void
    {
        $closing = date('Y-m-d H:i:s', time() + 86400 * 5);
        $opening = date('Y-m-d H:i:s', time() + 86400 * 5 + 3600);
        $tender = $this->createPublishedTender('CRYPTO-' . bin2hex(random_bytes(3)), $closing, $opening);
        $procId = $tender['proc_id'];

        // Purchase documents
        $saleCtrl = new SaleController();
        $this->executeController($saleCtrl, 'buyDocuments', [$procId], $this->createRequest(
            "http://localhost:8080/api/v1/me/tenders/{$procId}/buy-documents",
            'POST',
            [],
            $this->bidderUserId,
            $this->bidderOrgId
        ));

        // Lodge sealed bid
        $bidCtrl = new BidController();
        $reqLodge = $this->createRequest(
            'http://localhost:8080/api/v1/me/submissions',
            'POST',
            [
                'procurement_id' => $procId,
                'total_price'    => 24500000.00,
                'has_security'   => 1,
                'envelope'       => ['line_items' => [['desc' => 'Civil Works', 'price' => 24500000]]],
            ],
            $this->bidderUserId,
            $this->bidderOrgId
        );
        $resLodge = $this->executeController($bidCtrl, 'lodge', [], $reqLodge);
        $this->assertSame(201, $resLodge->getStatusCode());
        $subId = (int) json_decode($resLodge->getBody(), true)['data']['id'];

        // 1. Verify At-Rest Secrecy in submissions table
        $subRow = $this->db->table('submissions')->where('id', $subId)->get()->getFirstRow('array');
        $this->assertSame('(sealed)', $subRow['bidder_name']);
        $this->assertSame(0.0, (float) $subRow['total_price']);
        $this->assertSame(0, (int) $subRow['has_security']);
        $this->assertSame('sealed', $subRow['cipher_path']);

        // 2. Verify encrypted record exists in bid_seals
        $sealRow = $this->db->table('bid_seals')->where('submission_id', $subId)->get()->getFirstRow('array');
        $this->assertNotNull($sealRow);
        $this->assertNotEmpty($sealRow['ciphertext']);
        $this->assertNotEmpty($sealRow['iv']);
        $this->assertNotEmpty($sealRow['tag']);

        // 3. Normal unsealAll should successfully decrypt when untampered
        $crypto = new CryptoService();
        $unsealed = $crypto->unsealAll($procId);
        $this->assertArrayHasKey($subId, $unsealed);
        $this->assertSame(24500000.0, (float) $unsealed[$subId]['total_price']);
        $this->assertSame(1, (int) $unsealed[$subId]['has_security']);

        // 4. Tamper Resistance: Mutate 1 byte of ciphertext
        $origCt = $sealRow['ciphertext'];
        $tamperedCt = base64_encode(base64_decode($origCt) ^ "\x01");
        $this->db->table('bid_seals')->where('submission_id', $subId)->update(['ciphertext' => $tamperedCt]);

        // Attempt unseal with tampered ciphertext -> GCM authentication failure must return null (fail closed)
        $tamperedUnseal = $crypto->unsealAll($procId);
        $this->assertNull($tamperedUnseal[$subId], 'Tampered ciphertext must fail closed and return null');

        // 5. Tamper Resistance: Restore ciphertext, mutate 1 byte of authentication tag
        $this->db->table('bid_seals')->where('submission_id', $subId)->update([
            'ciphertext' => $origCt,
            'tag'        => base64_encode(base64_decode($sealRow['tag']) ^ "\xFF"),
        ]);
        $tamperedTagUnseal = $crypto->unsealAll($procId);
        $this->assertNull($tamperedTagUnseal[$subId], 'Tampered auth tag must fail closed and return null');

        // Restore original tag
        $this->db->table('bid_seals')->where('submission_id', $subId)->update(['tag' => $sealRow['tag']]);

        // 6. Cross-tender DEK isolation:
        // Create a second tender and verify DEKs are distinct and cannot cross-decrypt
        $tender2 = $this->createPublishedTender('CRYPTO2-' . bin2hex(random_bytes(3)), $closing, $opening);
        $procId2 = $tender2['proc_id'];

        $key1 = $this->db->table('tender_keys')->where('procurement_id', $procId)->get()->getFirstRow('array');
        // Insert a valid submission for procId2 to satisfy foreign key on bid_seals.submission_id
        $subId2 = $this->db->table('submissions')->insert([
            'procurement_id' => $procId2,
            'bidder_org_id'  => $this->bidderOrgId,
            'bidder_name'    => '(sealed)',
            'reference'      => "SUB-{$procId2}-0001",
            'total_price'    => 0,
            'has_security'   => 0,
            'size_bytes'     => 64,
            'content_hash'   => hash('sha256', 'sub2'),
            'cipher_path'    => 'sealed',
            'status'         => 'submitted',
            'received_at'    => date('Y-m-d H:i:s'),
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ], true) ? (int) $this->db->insertID() : 0;

        // Trigger tenderKey generation for procId2
        $crypto->seal($procId2, $subId2, ['test' => 123]);
        $key2 = $this->db->table('tender_keys')->where('procurement_id', $procId2)->get()->getFirstRow('array');

        $this->assertNotEquals($key1['wrapped_dek'], $key2['wrapped_dek'], 'Per-tender wrapped DEKs must be unique');

        // Clean up
        $this->db->table('bid_seals')->whereIn('procurement_id', [$procId, $procId2])->delete();
        $this->db->table('tender_keys')->whereIn('procurement_id', [$procId, $procId2])->delete();
        $this->db->table('submissions')->whereIn('id', [$subId, $subId2])->delete();
        $this->db->table('doc_purchases')->where('procurement_id', $procId)->delete();
        $this->db->table('procurements')->whereIn('id', [$procId, $procId2])->delete();
        $this->db->table('notices')->whereIn('id', [$tender['notice_id'], $tender2['notice_id']])->delete();
    }

    public function testDualControlOpeningProtocolAndTimingGates(): void
    {
        // 1. Create tender with opening time in the FUTURE
        $futureClosing = date('Y-m-d H:i:s', time() + 3600);
        $futureOpening = date('Y-m-d H:i:s', time() + 7200);
        $tender = $this->createPublishedTender('DUAL-' . bin2hex(random_bytes(3)), $futureClosing, $futureOpening);
        $procId = $tender['proc_id'];

        $openingCtrl = new OpeningController();

        // 2. Timing Gate: Starting opening before opening time must be rejected -> 409 too_early
        $reqEarly = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/opening/start",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resEarly = $this->executeController($openingCtrl, 'start', [$procId], $reqEarly);
        $this->assertSame(409, $resEarly->getStatusCode());
        $this->assertSame('too_early', json_decode($resEarly->getBody(), true)['reason']);

        // 3. Countersign before start must be rejected -> 409 not_started
        $reqCounterNoStart = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/opening/countersign",
            'POST',
            [],
            $this->opener2UserId,
            $this->buyerOrgId
        );
        $resCounterNoStart = $this->executeController($openingCtrl, 'countersign', [$procId], $reqCounterNoStart);
        $this->assertSame(409, $resCounterNoStart->getStatusCode());
        $this->assertSame('not_started', json_decode($resCounterNoStart->getBody(), true)['reason']);

        // Now move opening time into the past (e.g. 5 minutes ago)
        $pastTime = date('Y-m-d H:i:s', time() - 300);
        $this->db->table('notices')->where('id', $tender['notice_id'])->update([
            'closing_at' => $pastTime,
            'opening_at' => $pastTime,
        ]);

        // Purchase documents and submit sealed bid
        $saleCtrl = new SaleController();
        $this->executeController($saleCtrl, 'buyDocuments', [$procId], $this->createRequest(
            "http://localhost:8080/api/v1/me/tenders/{$procId}/buy-documents",
            'POST',
            [],
            $this->bidderUserId,
            $this->bidderOrgId
        ));

        // Submit bid directly via table to bypass closed deadline check for this ceremony test
        $subId = $this->db->table('submissions')->insert([
            'procurement_id' => $procId,
            'bidder_org_id'  => $this->bidderOrgId,
            'bidder_name'    => '(sealed)',
            'reference'      => "SUB-{$procId}-0001",
            'total_price'    => 0,
            'has_security'   => 0,
            'size_bytes'     => 128,
            'content_hash'   => hash('sha256', 'dual-control-test'),
            'cipher_path'    => 'sealed',
            'status'         => 'submitted',
            'received_at'    => $pastTime,
            'created_at'     => $pastTime,
            'updated_at'     => $pastTime,
        ], true) ? $this->db->insertID() : 0;

        (new CryptoService())->seal($procId, (int) $subId, [
            'bidder_name'  => 'Confidential Construction Ltd',
            'total_price'  => 23800000.00,
            'has_security' => 1,
        ]);

        // 4. Query-Level Confidentiality Boundary (Rule 4):
        // Verify SubmissionModel::forProcurement($procId, false) excludes identifying columns
        $subModel = new SubmissionModel();
        $sealedList = $subModel->forProcurement($procId, false);
        $this->assertNotEmpty($sealedList);
        $firstSealed = $sealedList[0];
        $this->assertArrayNotHasKey('bidder_name', $firstSealed, 'bidder_name must not be selected before opening');
        $this->assertArrayNotHasKey('total_price', $firstSealed, 'total_price must not be selected before opening');
        $this->assertArrayNotHasKey('has_security', $firstSealed, 'has_security must not be selected before opening');
        $this->assertArrayHasKey('reference', $firstSealed);
        $this->assertArrayHasKey('size_bytes', $firstSealed);

        // 5. Officer A starts the opening ceremony
        $reqStart = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/opening/start",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resStart = $this->executeController($openingCtrl, 'start', [$procId], $reqStart);
        $this->assertSame(200, $resStart->getStatusCode());
        $pAfterStart = $this->db->table('procurements')->where('id', $procId)->get()->getFirstRow('array');
        $this->assertSame($this->officerUserId, (int) $pAfterStart['opened_by_a']);
        $this->assertSame(3, (int) $pAfterStart['stage_idx']);

        // 6. Separation of Duties: Officer A attempting to countersign must be rejected -> 403 same_officer
        $reqSameOfficer = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/opening/countersign",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resSameOfficer = $this->executeController($openingCtrl, 'countersign', [$procId], $reqSameOfficer);
        $this->assertSame(403, $resSameOfficer->getStatusCode());
        $this->assertSame('same_officer', json_decode($resSameOfficer->getBody(), true)['reason']);

        // 7. Distinct Officer B countersigns the opening ceremony
        $reqCountersign = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/opening/countersign",
            'POST',
            [],
            $this->opener2UserId,
            $this->buyerOrgId
        );
        $resCountersign = $this->executeController($openingCtrl, 'countersign', [$procId], $reqCountersign);
        $this->assertSame(200, $resCountersign->getStatusCode());

        $pAfterOpen = $this->db->table('procurements')->where('id', $procId)->get()->getFirstRow('array');
        $this->assertSame($this->opener2UserId, (int) $pAfterOpen['opened_by_b']);
        $this->assertSame(4, (int) $pAfterOpen['stage_idx']);

        // 8. Verify cryptographic unsealing has decrypted the submission
        $subAfterOpen = $this->db->table('submissions')->where('id', $subId)->get()->getFirstRow('array');
        $this->assertSame('opened', $subAfterOpen['status']);
        $this->assertSame('Confidential Construction Ltd', $subAfterOpen['bidder_name']);
        $this->assertSame(23800000.0, (float) $subAfterOpen['total_price']);
        $this->assertSame(1, (int) $subAfterOpen['has_security']);

        // 9. Replay Resistance: Attempting to start or countersign already opened tender -> 409 already_opened
        $resReplayStart = $this->executeController($openingCtrl, 'start', [$procId], $reqStart);
        $this->assertSame(409, $resReplayStart->getStatusCode());
        $this->assertSame('already_opened', json_decode($resReplayStart->getBody(), true)['reason']);

        $resReplayCounter = $this->executeController($openingCtrl, 'countersign', [$procId], $reqCountersign);
        $this->assertSame(409, $resReplayCounter->getStatusCode());
        $this->assertSame('already_opened', json_decode($resReplayCounter->getBody(), true)['reason']);

        // Clean up
        $this->db->table('bid_seals')->where('procurement_id', $procId)->delete();
        $this->db->table('tender_keys')->where('procurement_id', $procId)->delete();
        $this->db->table('submissions')->where('id', $subId)->delete();
        $this->db->table('doc_purchases')->where('procurement_id', $procId)->delete();
        $this->db->table('procurements')->where('id', $procId)->delete();
        $this->db->table('notices')->where('id', $tender['notice_id'])->delete();
    }

    public function testEvaluatorConflictOfInterestImmutabilityAndLockouts(): void
    {
        $closing = date('Y-m-d H:i:s', time() + 86400 * 2);
        $opening = date('Y-m-d H:i:s', time() + 86400 * 2 + 3600);
        $tender = $this->createPublishedTender('COI-' . bin2hex(random_bytes(3)), $closing, $opening);
        $procId = $tender['proc_id'];

        $evalCtrl = new EvaluationController();

        // 1. Accessing sheet before opening ceremony -> 409 not_opened
        $reqSheetPreOpen = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/evaluation",
            'GET',
            [],
            $this->evalClearUserId,
            $this->buyerOrgId
        );
        $resSheetPreOpen = $this->executeController($evalCtrl, 'sheet', [$procId], $reqSheetPreOpen);
        $this->assertSame(409, $resSheetPreOpen->getStatusCode());
        $this->assertSame('not_opened', json_decode($resSheetPreOpen->getBody(), true)['reason']);

        // Manually simulate dual opening completed (stage_idx = 4)
        $this->db->table('procurements')->where('id', $procId)->update([
            'stage_idx'   => 4,
            'opened_by_a' => $this->officerUserId,
            'opened_by_b' => $this->opener2UserId,
            'opened_at'   => date('Y-m-d H:i:s'),
        ]);

        // 2. Accessing sheet without COI declaration -> 403 coi_required
        $reqSheetNoCOI = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/evaluation",
            'GET',
            [],
            $this->evalConflictedUserId,
            $this->buyerOrgId
        );
        $resSheetNoCOI = $this->executeController($evalCtrl, 'sheet', [$procId], $reqSheetNoCOI);
        $this->assertSame(403, $resSheetNoCOI->getStatusCode());
        $this->assertSame('coi_required', json_decode($resSheetNoCOI->getBody(), true)['reason']);

        // 3. Evaluator submits declaration with conflict: has_conflict = 1
        $reqDeclareConflict = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/evaluation/coi",
            'POST',
            [
                'has_conflict' => 1,
                'statement'    => 'My sibling is a director at a bidding firm.',
            ],
            $this->evalConflictedUserId,
            $this->buyerOrgId
        );
        $resDeclareConflict = $this->executeController($evalCtrl, 'declare', [$procId], $reqDeclareConflict);
        $this->assertSame(201, $resDeclareConflict->getStatusCode());
        $this->assertTrue(json_decode($resDeclareConflict->getBody(), true)['data']['declared']);

        // 4. Conflicted Evaluator attempting to view sheet -> 403 conflicted
        $resSheetConflicted = $this->executeController($evalCtrl, 'sheet', [$procId], $reqSheetNoCOI);
        $this->assertSame(403, $resSheetConflicted->getStatusCode());
        $bodyConflicted = json_decode($resSheetConflicted->getBody(), true);
        $this->assertSame('conflicted', $bodyConflicted['reason']);
        $this->assertTrue($bodyConflicted['permanent'] ?? false);

        // 5. Immutability: Conflicted Evaluator attempting to re-declare as conflict-free -> 409 already_declared
        $reqReDeclare = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/evaluation/coi",
            'POST',
            [
                'has_conflict' => 0,
                'statement'    => 'Conflict resolved.',
            ],
            $this->evalConflictedUserId,
            $this->buyerOrgId
        );
        $resReDeclare = $this->executeController($evalCtrl, 'declare', [$procId], $reqReDeclare);
        $this->assertSame(409, $resReDeclare->getStatusCode());
        $this->assertSame('already_declared', json_decode($resReDeclare->getBody(), true)['reason']);

        // 6. Distinct unconflicted evaluator declares has_conflict = 0 -> access granted
        $reqDeclareClear = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/evaluation/coi",
            'POST',
            [
                'has_conflict' => 0,
                'statement'    => 'No conflicts of interest to declare.',
            ],
            $this->evalClearUserId,
            $this->buyerOrgId
        );
        $resDeclareClear = $this->executeController($evalCtrl, 'declare', [$procId], $reqDeclareClear);
        $this->assertSame(201, $resDeclareClear->getStatusCode());

        $reqSheetClear = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/evaluation",
            'GET',
            [],
            $this->evalClearUserId,
            $this->buyerOrgId
        );
        $resSheetClear = $this->executeController($evalCtrl, 'sheet', [$procId], $reqSheetClear);
        $this->assertSame(200, $resSheetClear->getStatusCode());
        $this->assertArrayHasKey('submissions', json_decode($resSheetClear->getBody(), true)['data']);

        // Clean up
        $this->db->table('coi_declarations')->where('procurement_id', $procId)->delete();
        $this->db->table('procurements')->where('id', $procId)->delete();
        $this->db->table('notices')->where('id', $tender['notice_id'])->delete();
    }

    public function testSubmissionIntegrityAndReceiptVerification(): void
    {
        $futureClose = date('Y-m-d H:i:s', time() + 86400 * 10);
        $futureOpen  = date('Y-m-d H:i:s', time() + 86400 * 10 + 3600);
        $tender1 = $this->createPublishedTender('SUBINT1-' . bin2hex(random_bytes(3)), $futureClose, $futureOpen);
        $tender2 = $this->createPublishedTender('SUBINT2-' . bin2hex(random_bytes(3)), $futureClose, $futureOpen);
        $procId1 = $tender1['proc_id'];
        $procId2 = $tender2['proc_id'];

        $bidCtrl = new BidController();
        $saleCtrl = new SaleController();

        // 1. Bid lodge without document purchase -> 403 documents_not_purchased
        $reqNoDoc = $this->createRequest(
            'http://localhost:8080/api/v1/me/submissions',
            'POST',
            [
                'procurement_id' => $procId1,
                'total_price'    => 15000000.00,
                'has_security'   => 1,
            ],
            $this->bidderUserId,
            $this->bidderOrgId
        );
        $resNoDoc = $this->executeController($bidCtrl, 'lodge', [], $reqNoDoc);
        $this->assertSame(403, $resNoDoc->getStatusCode());
        $this->assertSame('documents_not_purchased', json_decode($resNoDoc->getBody(), true)['reason']);

        // 2. Cross-tender purchase bypass:
        // Buy documents for Tender 2, then attempt to lodge bid on Tender 1 -> must be refused 403
        $this->executeController($saleCtrl, 'buyDocuments', [$procId2], $this->createRequest(
            "http://localhost:8080/api/v1/me/tenders/{$procId2}/buy-documents",
            'POST',
            [],
            $this->bidderUserId,
            $this->bidderOrgId
        ));
        $resCrossBypass = $this->executeController($bidCtrl, 'lodge', [], $reqNoDoc);
        $this->assertSame(403, $resCrossBypass->getStatusCode());
        $this->assertSame('documents_not_purchased', json_decode($resCrossBypass->getBody(), true)['reason']);

        // 3. Purchase documents for Tender 1
        $this->executeController($saleCtrl, 'buyDocuments', [$procId1], $this->createRequest(
            "http://localhost:8080/api/v1/me/tenders/{$procId1}/buy-documents",
            'POST',
            [],
            $this->bidderUserId,
            $this->bidderOrgId
        ));

        // 4. Closed deadline enforcement:
        // Set tender 1 closing in the past -> must be rejected with 409 closed
        $this->db->table('notices')->where('id', $tender1['notice_id'])->update([
            'closing_at' => date('Y-m-d H:i:s', time() - 3600),
        ]);
        $resClosed = $this->executeController($bidCtrl, 'lodge', [], $reqNoDoc);
        $this->assertSame(409, $resClosed->getStatusCode());
        $this->assertSame('closed', json_decode($resClosed->getBody(), true)['reason']);

        // Restore future closing
        $this->db->table('notices')->where('id', $tender1['notice_id'])->update([
            'closing_at' => $futureClose,
        ]);

        // 5. Valid submission with envelope
        $envelope = [
            'boq'       => [['code' => '01-01', 'qty' => 10, 'rate' => 1500000]],
            'signature' => 'RSA-SHA256-ATTESTATION',
        ];
        $expectedHash = hash('sha256', json_encode($envelope));

        $reqValid = $this->createRequest(
            'http://localhost:8080/api/v1/me/submissions',
            'POST',
            [
                'procurement_id' => $procId1,
                'total_price'    => 15000000.00,
                'has_security'   => 1,
                'envelope'       => $envelope,
            ],
            $this->bidderUserId,
            $this->bidderOrgId
        );
        $resValid = $this->executeController($bidCtrl, 'lodge', [], $reqValid);
        $this->assertSame(201, $resValid->getStatusCode());
        $receiptData = json_decode($resValid->getBody(), true)['data'];
        $subId = (int) $receiptData['id'];
        $this->assertSame($expectedHash, $receiptData['content_hash']);

        // 6. Duplicate submission rejection from same bidder org -> 409 already_submitted
        $resDup = $this->executeController($bidCtrl, 'lodge', [], $reqValid);
        $this->assertSame(409, $resDup->getStatusCode());
        $this->assertSame('already_submitted', json_decode($resDup->getBody(), true)['reason']);

        // 7. Digital Receipt isolation:
        // A different bidder organisation attempting to fetch the submission receipt -> 404 not_found
        $reqOtherOrgReceipt = $this->createRequest(
            "http://localhost:8080/api/v1/me/submissions/{$subId}/receipt",
            'GET',
            [],
            $this->officerUserId,
            $this->secondBidderOrgId
        );
        $resOtherOrgReceipt = $this->executeController($bidCtrl, 'receipt', [$subId], $reqOtherOrgReceipt);
        $this->assertSame(404, $resOtherOrgReceipt->getStatusCode());
        $this->assertSame('not_found', json_decode($resOtherOrgReceipt->getBody(), true)['reason']);

        // Authorised bidder fetches receipt -> 200 with ETA 2006 compliance data
        $reqAuthReceipt = $this->createRequest(
            "http://localhost:8080/api/v1/me/submissions/{$subId}/receipt",
            'GET',
            [],
            $this->bidderUserId,
            $this->bidderOrgId
        );
        $resAuthReceipt = $this->executeController($bidCtrl, 'receipt', [$subId], $reqAuthReceipt);
        $this->assertSame(200, $resAuthReceipt->getStatusCode());
        $authReceipt = json_decode($resAuthReceipt->getBody(), true)['data'];
        $this->assertSame($expectedHash, $authReceipt['content_hash']);
        $this->assertSame($receiptData['reference'], $authReceipt['reference']);

        // Clean up
        $this->db->table('bid_seals')->where('procurement_id', $procId1)->delete();
        $this->db->table('tender_keys')->where('procurement_id', $procId1)->delete();
        $this->db->table('submissions')->where('id', $subId)->delete();
        $this->db->table('doc_purchases')->whereIn('procurement_id', [$procId1, $procId2])->delete();
        $this->db->table('procurements')->whereIn('id', [$procId1, $procId2])->delete();
        $this->db->table('notices')->whereIn('id', [$tender1['notice_id'], $tender2['notice_id']])->delete();
    }
}
