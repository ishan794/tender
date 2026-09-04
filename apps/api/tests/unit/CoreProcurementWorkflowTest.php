<?php

namespace Tests\Unit;

use App\Controllers\Api\V1\Authority\AwardController;
use App\Controllers\Api\V1\Authority\ContractController;
use App\Controllers\Api\V1\Authority\EvaluationController;
use App\Controllers\Api\V1\Authority\OpeningController;
use App\Controllers\Api\V1\Authority\PlanController;
use App\Controllers\Api\V1\Authority\SaleController;
use App\Controllers\Api\V1\Authority\TenderController;
use App\Controllers\Api\V1\Member\BidController;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Validates Phase 5: Core Procurement Workflow end-to-end.
 * Covers: Planning -> Drafting -> 4-Eyes Approval -> Publication -> Document Purchase
 * -> Sealed Bidding -> Dual-Control Opening -> COI & Evaluation -> Standstill Award -> Contract.
 */
class CoreProcurementWorkflowTest extends CIUnitTestCase
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

        // Resolve test fixtures from seed data
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
                'nm'  => 'Test Officer',
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

    // =========================================================================
    // SECTION 1: Annual Procurement Planning (PlanController)
    // =========================================================================

    public function testPlanLifecycleAndFourEyesSeparation(): void
    {
        $controller = new PlanController();

        // 1. Create plan line above threshold (threshold is 50,000,000, value is 60,000,000)
        $reqCreate = $this->createRequest(
            'http://localhost:8080/api/v1/authority/plans',
            'POST',
            [
                'title'              => 'Major Bridge Overhaul 2026',
                'year'               => 2026,
                'department'         => 'Civil Engineering',
                'estimated_value'    => 60000000.00,
                'procurement_method' => 'open',
            ],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resCreate = $this->executeController($controller, 'create', [], $reqCreate);
        $this->assertSame(201, $resCreate->getStatusCode());
        $planData = json_decode($resCreate->getBody(), true)['data'];
        $planId = (int) $planData['id'];
        $this->assertSame('draft', $planData['status']);
        $this->assertSame(60000000.0, (float) $planData['estimated_value']);

        // 2. Submit draft plan for approval
        $reqSubmit = $this->createRequest(
            "http://localhost:8080/api/v1/authority/plans/{$planId}/submit",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resSubmit = $this->executeController($controller, 'submit', [$planId], $reqSubmit);
        $this->assertSame(200, $resSubmit->getStatusCode());
        $this->assertSame('submitted', json_decode($resSubmit->getBody(), true)['data']['status']);

        // 3. Four-eyes enforcement: Creator attempting to approve above threshold must be rejected (403 self_approval)
        $reqSelfApprove = $this->createRequest(
            "http://localhost:8080/api/v1/authority/plans/{$planId}/approve",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resSelfApprove = $this->executeController($controller, 'approve', [$planId], $reqSelfApprove);
        $this->assertSame(403, $resSelfApprove->getStatusCode());
        $bodySelf = json_decode($resSelfApprove->getBody(), true);
        $this->assertSame('self_approval', $bodySelf['reason']);

        // 4. Distinct approver approves plan line
        $reqApprove = $this->createRequest(
            "http://localhost:8080/api/v1/authority/plans/{$planId}/approve",
            'POST',
            [],
            $this->approverUserId,
            $this->buyerOrgId
        );
        $resApprove = $this->executeController($controller, 'approve', [$planId], $reqApprove);
        $this->assertSame(200, $resApprove->getStatusCode());
        $approvedData = json_decode($resApprove->getBody(), true)['data'];
        $this->assertSame('approved', $approvedData['status']);
        $this->assertSame($this->approverUserId, (int) $approvedData['approved_by']);
        $this->assertNotNull($approvedData['approved_at']);

        // 5. Revision: Revising approved plan creates a new draft revision preserving history
        $reqRevise = $this->createRequest(
            "http://localhost:8080/api/v1/authority/plans/{$planId}/revise",
            'POST',
            ['estimated_value' => 65000000.00],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resRevise = $this->executeController($controller, 'revise', [$planId], $reqRevise);
        $this->assertSame(201, $resRevise->getStatusCode());
        $revisionData = json_decode($resRevise->getBody(), true)['data'];
        $newPlanId = (int) $revisionData['id'];
        $this->assertSame('draft', $revisionData['status']);
        $this->assertSame($planId, (int) $revisionData['revision_of']);
        $this->assertSame(65000000.0, (float) $revisionData['estimated_value']);

        // Verify original row marked 'revised'
        $oldRow = $this->db->table('procurement_plans')->where('id', $planId)->get()->getFirstRow('array');
        $this->assertSame('revised', $oldRow['status']);

        // Clean up created plans
        $this->db->table('procurement_plans')->whereIn('id', [$planId, $newPlanId])->delete();
    }

    // =========================================================================
    // SECTION 2: Tender Drafting, 4-Eyes Approval & Publication (TenderController)
    // =========================================================================

    public function testTenderDraftingDateValidationAndFourEyesApproval(): void
    {
        $controller = new TenderController();

        // 1. Date validation: Closing in the past is rejected
        $reqPast = $this->createRequest(
            'http://localhost:8080/api/v1/authority/tenders',
            'POST',
            [
                'title'      => 'Past Tender',
                'reference'  => 'REF-PAST-01',
                'closing_at' => date('Y-m-d H:i:s', time() - 3600),
            ],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resPast = $this->executeController($controller, 'create', [], $reqPast);
        $this->assertSame(422, $resPast->getStatusCode());
        $this->assertSame('closing_in_past', json_decode($resPast->getBody(), true)['reason']);

        // 2. Date validation: Opening before closing is rejected
        $reqInvalidOpening = $this->createRequest(
            'http://localhost:8080/api/v1/authority/tenders',
            'POST',
            [
                'title'      => 'Invalid Opening Tender',
                'reference'  => 'REF-OP-01',
                'closing_at' => date('Y-m-d H:i:s', time() + 86400 * 14),
                'opening_at' => date('Y-m-d H:i:s', time() + 86400 * 7),
            ],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resInvalidOpening = $this->executeController($controller, 'create', [], $reqInvalidOpening);
        $this->assertSame(422, $resInvalidOpening->getStatusCode());
        $this->assertSame('opening_before_closing', json_decode($resInvalidOpening->getBody(), true)['reason']);

        // 3. Create valid draft tender above approval threshold (55,000,000 >= 50,000,000)
        $closingAt = date('Y-m-d H:i:s', time() + 86400 * 14);
        $openingAt = date('Y-m-d H:i:s', time() + 86400 * 14 + 3600);
        $ref = 'RDA-HW-' . bin2hex(random_bytes(3));
        $reqCreate = $this->createRequest(
            'http://localhost:8080/api/v1/authority/tenders',
            'POST',
            [
                'title'           => 'Central Expressway Resurfacing Segment 4',
                'reference'       => $ref,
                'closing_at'      => $closingAt,
                'opening_at'      => $openingAt,
                'estimated_value' => 55000000.00,
                'document_fee'    => 5000.00,
            ],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resCreate = $this->executeController($controller, 'create', [], $reqCreate);
        $this->assertSame(201, $resCreate->getStatusCode());
        $tData = json_decode($resCreate->getBody(), true)['data'];
        $procId = (int) $tData['id'];
        $this->assertSame(0, (int) $tData['stage_idx']);
        $this->assertSame('draft', $tData['notice_status']);

        // 4. Submit tender for approval (Draft 0 -> Approval 1)
        $reqSubApp = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/submit-for-approval",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resSubApp = $this->executeController($controller, 'submitForApproval', [$procId], $reqSubApp);
        $this->assertSame(200, $resSubApp->getStatusCode());
        $this->assertSame('Approval', json_decode($resSubApp->getBody(), true)['data']['stage']);

        // 5. Attempt publication before approval must be rejected (409 not_approved)
        $reqPrematurePub = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/publish",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resPrematurePub = $this->executeController($controller, 'publish', [$procId], $reqPrematurePub);
        $this->assertSame(409, $resPrematurePub->getStatusCode());
        $this->assertSame('not_approved', json_decode($resPrematurePub->getBody(), true)['reason']);

        // 6. Four-eyes enforcement: Creator attempting to approve above threshold must be rejected (403 self_approval)
        $reqSelfApprove = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/approve",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resSelfApprove = $this->executeController($controller, 'approve', [$procId], $reqSelfApprove);
        $this->assertSame(403, $resSelfApprove->getStatusCode());
        $this->assertSame('self_approval', json_decode($resSelfApprove->getBody(), true)['reason']);

        // 7. Distinct approver approves tender
        $reqApprove = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/approve",
            'POST',
            [],
            $this->approverUserId,
            $this->buyerOrgId
        );
        $resApprove = $this->executeController($controller, 'approve', [$procId], $reqApprove);
        $this->assertSame(200, $resApprove->getStatusCode());

        // 8. Publish approved tender to public catalogue
        $reqPublish = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/publish",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resPublish = $this->executeController($controller, 'publish', [$procId], $reqPublish);
        $this->assertSame(200, $resPublish->getStatusCode());
        $pubData = json_decode($resPublish->getBody(), true)['data'];
        $this->assertSame('Published', $pubData['stage']);

        // Verify notice status is updated to 'published' in database
        $procRow = $this->db->table('procurements')->where('id', $procId)->get()->getFirstRow('array');
        $noticeRow = $this->db->table('notices')->where('id', $procRow['notice_id'])->get()->getFirstRow('array');
        $this->assertSame(2, (int) $procRow['stage_idx']);
        $this->assertSame('published', $noticeRow['status']);

        // Cleanup
        $this->db->table('procurements')->where('id', $procId)->delete();
        $this->db->table('notices')->where('id', $procRow['notice_id'])->delete();
    }

    // =========================================================================
    // SECTION 3: Document Purchase & Sealed-Bid Lodgement (BidController & SaleController)
    // =========================================================================

    public function testDocumentPurchaseAndSealedBiddingLifecycle(): void
    {
        // Setup: Create an approved & published tender
        $closingAt = date('Y-m-d H:i:s', time() + 86400 * 10);
        $openingAt = date('Y-m-d H:i:s', time() + 86400 * 10 + 3600);
        $ref = 'TEST-SEAL-' . bin2hex(random_bytes(3));

        $noticeId = (int) $this->db->table('notices')->insert([
            'reference'  => $ref,
            'slug'       => url_title($ref . '-title', '-', true),
            'title'      => 'Sealed Bid Test Project',
            'org_id'     => $this->buyerOrgId,
            'closing_at' => $closingAt,
            'opening_at' => $openingAt,
            'status'     => 'published',
            'created_at' => date('Y-m-d H:i:s'),
        ], true) ? $this->db->insertID() : 0;

        $this->db->table('procurements')->insert([
            'org_id'       => $this->buyerOrgId,
            'notice_id'    => $noticeId,
            'stage_idx'    => 2, // Published
            'created_by'   => $this->officerUserId,
            'approved_by'  => $this->approverUserId,
            'approved_at'  => date('Y-m-d H:i:s'),
            'published_at' => date('Y-m-d H:i:s'),
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $procId = (int) $this->db->insertID();

        $bidController = new BidController();
        $saleController = new SaleController();

        // 1. Lodging bid without purchasing documents is rejected (403 documents_not_purchased)
        $reqLodgeNoDoc = $this->createRequest(
            'http://localhost:8080/api/v1/me/submissions',
            'POST',
            [
                'procurement_id' => $procId,
                'total_price'    => 48000000.00,
                'has_security'   => 1,
            ],
            $this->bidderUserId,
            $this->bidderOrgId
        );
        $resLodgeNoDoc = $this->executeController($bidController, 'lodge', [], $reqLodgeNoDoc);
        $this->assertSame(403, $resLodgeNoDoc->getStatusCode());
        $this->assertSame('documents_not_purchased', json_decode($resLodgeNoDoc->getBody(), true)['reason']);

        // 2. Bidder purchases bidding documents
        $reqBuyDoc = $this->createRequest(
            "http://localhost:8080/api/v1/me/tenders/{$procId}/buy-documents",
            'POST',
            ['amount' => 5000.00],
            $this->bidderUserId,
            $this->bidderOrgId
        );
        $resBuyDoc = $this->executeController($saleController, 'buyDocuments', [$procId], $reqBuyDoc);
        $this->assertSame(201, $resBuyDoc->getStatusCode());
        $this->assertTrue(json_decode($resBuyDoc->getBody(), true)['data']['purchased']);

        // 3. Lodging bid with purchased documents succeeds and seals sensitive data
        $reqLodge = $this->createRequest(
            'http://localhost:8080/api/v1/me/submissions',
            'POST',
            [
                'procurement_id' => $procId,
                'total_price'    => 48000000.00,
                'has_security'   => 1,
                'envelope'       => ['financial' => '48M', 'tech_score' => 95],
            ],
            $this->bidderUserId,
            $this->bidderOrgId
        );
        $resLodge = $this->executeController($bidController, 'lodge', [], $reqLodge);
        $this->assertSame(201, $resLodge->getStatusCode());
        $subData = json_decode($resLodge->getBody(), true)['data'];
        $subId = (int) $subData['id'];
        $this->assertNotEmpty($subData['reference']);
        $this->assertNotEmpty($subData['content_hash']);

        // Verify that sensitive fields in submissions table are SANITIZED / SEALED at rest
        $subRow = $this->db->table('submissions')->where('id', $subId)->get()->getFirstRow('array');
        $this->assertSame('(sealed)', $subRow['bidder_name']);
        $this->assertSame(0.0, (float) $subRow['total_price']);
        $this->assertSame('sealed', $subRow['cipher_path']);

        // Verify that encrypted seal exists in bid_seals table
        $sealRow = $this->db->table('bid_seals')->where('submission_id', $subId)->get()->getFirstRow('array');
        $this->assertNotNull($sealRow);
        $this->assertNotEmpty($sealRow['ciphertext']);

        // 4. Duplicate bid submission by same bidder organisation is rejected (409 already_submitted)
        $reqDuplicate = $this->createRequest(
            'http://localhost:8080/api/v1/me/submissions',
            'POST',
            [
                'procurement_id' => $procId,
                'total_price'    => 45000000.00,
            ],
            $this->bidderUserId,
            $this->bidderOrgId
        );
        $resDuplicate = $this->executeController($bidController, 'lodge', [], $reqDuplicate);
        $this->assertSame(409, $resDuplicate->getStatusCode());
        $this->assertSame('already_submitted', json_decode($resDuplicate->getBody(), true)['reason']);

        // Cleanup
        $this->db->table('bid_seals')->where('procurement_id', $procId)->delete();
        $this->db->table('tender_keys')->where('procurement_id', $procId)->delete();
        $this->db->table('submissions')->where('procurement_id', $procId)->delete();
        $this->db->table('doc_purchases')->where('procurement_id', $procId)->delete();
        $this->db->table('procurements')->where('id', $procId)->delete();
        $this->db->table('notices')->where('id', $noticeId)->delete();
    }

    // =========================================================================
    // SECTION 4: Dual-Control Opening Ceremony (OpeningController)
    // =========================================================================

    public function testDualControlOpeningCeremonyAndDecryption(): void
    {
        // Setup: Create a procurement with a sealed bid
        $closingAt = date('Y-m-d H:i:s', time() - 60); // Already closed
        $openingAt = date('Y-m-d H:i:s', time() - 30); // Opening time arrived
        $ref = 'TEST-OPEN-' . bin2hex(random_bytes(3));

        $noticeId = (int) $this->db->table('notices')->insert([
            'reference'  => $ref,
            'slug'       => url_title($ref . '-title', '-', true),
            'title'      => 'Dual Control Opening Test',
            'org_id'     => $this->buyerOrgId,
            'closing_at' => $closingAt,
            'opening_at' => $openingAt,
            'status'     => 'published',
            'created_at' => date('Y-m-d H:i:s'),
        ], true) ? $this->db->insertID() : 0;

        $this->db->table('procurements')->insert([
            'org_id'       => $this->buyerOrgId,
            'notice_id'    => $noticeId,
            'stage_idx'    => 2, // Published
            'created_by'   => $this->officerUserId,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $procId = (int) $this->db->insertID();

        // Create a sealed submission
        $this->db->table('submissions')->insert([
            'procurement_id' => $procId,
            'bidder_org_id'  => $this->bidderOrgId,
            'bidder_name'    => '(sealed)',
            'total_price'    => 0,
            'has_security'   => 0,
            'cipher_path'    => 'sealed',
            'reference'      => 'SUB-OPEN-001',
            'status'         => 'submitted',
            'received_at'    => date('Y-m-d H:i:s'),
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $subId = (int) $this->db->insertID();

        // Seal real plaintext
        $expectedBidderName = 'Lanka Constructions (Pvt) Ltd';
        $expectedPrice = 42500000.00;
        service('crypto')->seal($procId, $subId, [
            'bidder_name'  => $expectedBidderName,
            'total_price'  => $expectedPrice,
            'has_security' => 1,
        ]);

        $openingController = new OpeningController();

        // 1. Before opening, viewing submissions withholds figures
        $reqSubs = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/submissions",
            'GET',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resSubs = $this->executeController($openingController, 'submissions', [$procId], $reqSubs);
        $this->assertSame(200, $resSubs->getStatusCode());
        $subsMeta = json_decode($resSubs->getBody(), true)['meta'];
        $this->assertFalse($subsMeta['opened']);
        $this->assertIsArray($subsMeta['withheld']);
        $this->assertSame(\App\Models\SubmissionModel::WITHHELD_REASON, $subsMeta['withheld_reason']);

        // 2. Step 1: Officer A starts the opening ceremony
        $reqStart = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/opening/start",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resStart = $this->executeController($openingController, 'start', [$procId], $reqStart);
        $this->assertSame(200, $resStart->getStatusCode());
        $startData = json_decode($resStart->getBody(), true)['data'];
        $this->assertSame($this->officerUserId, (int) $startData['started_by']);

        // Verify stage is now 3 (Closed)
        $procStage3 = $this->db->table('procurements')->where('id', $procId)->get()->getFirstRow('array');
        $this->assertSame(3, (int) $procStage3['stage_idx']);
        $this->assertSame($this->officerUserId, (int) $procStage3['opened_by_a']);

        // 3. Dual-control enforcement: Same Officer A cannot countersign (403 same_officer)
        $reqSameCounter = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/opening/countersign",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resSameCounter = $this->executeController($openingController, 'countersign', [$procId], $reqSameCounter);
        $this->assertSame(403, $resSameCounter->getStatusCode());
        $this->assertSame('same_officer', json_decode($resSameCounter->getBody(), true)['reason']);

        // 4. Distinct Officer B countersigns the opening
        $reqCounter = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/opening/countersign",
            'POST',
            [],
            $this->opener2UserId,
            $this->buyerOrgId
        );
        $resCounter = $this->executeController($openingController, 'countersign', [$procId], $reqCounter);
        $this->assertSame(200, $resCounter->getStatusCode());
        $counterMeta = json_decode($resCounter->getBody(), true)['meta'];
        $this->assertTrue($counterMeta['opened']);

        // 5. Verify cryptographic unsealing: Submissions row has real values restored
        $unsealedSub = $this->db->table('submissions')->where('id', $subId)->get()->getFirstRow('array');
        $this->assertSame($expectedBidderName, $unsealedSub['bidder_name']);
        $this->assertSame($expectedPrice, (float) $unsealedSub['total_price']);
        $this->assertSame(1, (int) $unsealedSub['has_security']);
        $this->assertSame('opened', $unsealedSub['status']);

        // Verify stage advanced to 4 (Opened)
        $procStage4 = $this->db->table('procurements')->where('id', $procId)->get()->getFirstRow('array');
        $this->assertSame(4, (int) $procStage4['stage_idx']);
        $this->assertSame($this->opener2UserId, (int) $procStage4['opened_by_b']);

        // Cleanup
        $this->db->table('bid_seals')->where('procurement_id', $procId)->delete();
        $this->db->table('tender_keys')->where('procurement_id', $procId)->delete();
        $this->db->table('submissions')->where('procurement_id', $procId)->delete();
        $this->db->table('procurements')->where('id', $procId)->delete();
        $this->db->table('notices')->where('id', $noticeId)->delete();
    }

    // =========================================================================
    // SECTION 5: Conflict of Interest & Evaluation Scoring (EvaluationController)
    // =========================================================================

    public function testConflictOfInterestDeclarationAndEvaluationScoring(): void
    {
        // Setup: Create an opened procurement (stage_idx = 4)
        $ref = 'TEST-EVAL-' . bin2hex(random_bytes(3));
        $noticeId = (int) $this->db->table('notices')->insert([
            'reference'  => $ref,
            'slug'       => url_title($ref . '-title', '-', true),
            'title'      => 'Evaluation Scoring Test',
            'org_id'     => $this->buyerOrgId,
            'closing_at' => date('Y-m-d H:i:s', time() - 3600),
            'status'     => 'published',
            'created_at' => date('Y-m-d H:i:s'),
        ], true) ? $this->db->insertID() : 0;

        $this->db->table('procurements')->insert([
            'org_id'       => $this->buyerOrgId,
            'notice_id'    => $noticeId,
            'stage_idx'    => 4, // Opened
            'opened_by_a'  => $this->officerUserId,
            'opened_by_b'  => $this->opener2UserId,
            'opened_at'    => date('Y-m-d H:i:s'),
            'created_by'   => $this->officerUserId,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $procId = (int) $this->db->insertID();

        // Create submission
        $this->db->table('submissions')->insert([
            'procurement_id' => $procId,
            'bidder_org_id'  => $this->bidderOrgId,
            'bidder_name'    => 'Lanka Constructions',
            'total_price'    => 35000000.00,
            'reference'      => 'SUB-EVAL-01',
            'status'         => 'opened',
            'received_at'    => date('Y-m-d H:i:s'),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        $subId = (int) $this->db->insertID();

        $evalController = new EvaluationController();

        // 1. Pre-declaration check: Accessing sheet without COI declaration is blocked (403 coi_required)
        $reqNoCoi = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/evaluation",
            'GET',
            [],
            $this->evalClearUserId,
            $this->buyerOrgId
        );
        $resNoCoi = $this->executeController($evalController, 'sheet', [$procId], $reqNoCoi);
        $this->assertSame(403, $resNoCoi->getStatusCode());
        $this->assertSame('coi_required', json_decode($resNoCoi->getBody(), true)['reason']);

        // 2. Conflicted evaluator declares conflict (has_conflict = 1)
        $reqDeclareConf = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/evaluation/coi",
            'POST',
            ['has_conflict' => 1, 'statement' => 'Family relation to bidder director'],
            $this->evalConflictedUserId,
            $this->buyerOrgId
        );
        $resDeclareConf = $this->executeController($evalController, 'declare', [$procId], $reqDeclareConf);
        $this->assertSame(201, $resDeclareConf->getStatusCode());

        // Conflicted evaluator is PERMANENTLY LOCKED OUT (403 conflicted)
        $reqConfSheet = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/evaluation",
            'GET',
            [],
            $this->evalConflictedUserId,
            $this->buyerOrgId
        );
        $resConfSheet = $this->executeController($evalController, 'sheet', [$procId], $reqConfSheet);
        $this->assertSame(403, $resConfSheet->getStatusCode());
        $this->assertSame('conflicted', json_decode($resConfSheet->getBody(), true)['reason']);

        // 3. Unconflicted evaluator declares NO conflict (has_conflict = 0)
        $reqDeclareClear = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/evaluation/coi",
            'POST',
            ['has_conflict' => 0, 'statement' => 'No known conflicts'],
            $this->evalClearUserId,
            $this->buyerOrgId
        );
        $resDeclareClear = $this->executeController($evalController, 'declare', [$procId], $reqDeclareClear);
        $this->assertSame(201, $resDeclareClear->getStatusCode());

        // 4. Set up evaluation criteria
        $reqCrit = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/evaluation/criteria",
            'POST',
            [
                'criteria' => [
                    ['label' => 'Technical Capability', 'type' => 'weighted', 'weight' => 60, 'max_score' => 100],
                    ['label' => 'CIDA Registration Compliance', 'type' => 'pass_fail', 'weight' => 40, 'max_score' => 100],
                ],
            ],
            $this->evalClearUserId,
            $this->buyerOrgId
        );
        $resCrit = $this->executeController($evalController, 'criteria', [$procId], $reqCrit);
        $this->assertSame(201, $resCrit->getStatusCode());
        $critList = json_decode($resCrit->getBody(), true)['data'];
        $critId = (int) $critList[0]['id'];

        // 5. Unconflicted evaluator can view sheet
        $reqSheet = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/evaluation",
            'GET',
            [],
            $this->evalClearUserId,
            $this->buyerOrgId
        );
        $resSheet = $this->executeController($evalController, 'sheet', [$procId], $reqSheet);
        $this->assertSame(200, $resSheet->getStatusCode());
        $sheetData = json_decode($resSheet->getBody(), true)['data'];
        $this->assertCount(2, $sheetData['criteria']);
        $this->assertCount(1, $sheetData['submissions']);

        // 6. Evaluator scores submission -> advances stage to 5 (Evaluation)
        $reqScore = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/evaluation/scores",
            'POST',
            [
                'scores' => [
                    [
                        'submission_id' => $subId,
                        'criterion_id'  => $critId,
                        'score'         => 88.5,
                        'passed'        => 1,
                        'note'          => 'Strong past track record',
                    ],
                ],
            ],
            $this->evalClearUserId,
            $this->buyerOrgId
        );
        $resScore = $this->executeController($evalController, 'score', [$procId], $reqScore);
        $this->assertSame(200, $resScore->getStatusCode());
        $this->assertSame('Evaluation', json_decode($resScore->getBody(), true)['meta']['stage']);

        // Verify stage in DB is now 5 (Evaluation)
        $procStage5 = $this->db->table('procurements')->where('id', $procId)->get()->getFirstRow('array');
        $this->assertSame(5, (int) $procStage5['stage_idx']);

        // Cleanup
        $this->db->table('eval_scores')->where('submission_id', $subId)->delete();
        $this->db->table('eval_criteria')->where('procurement_id', $procId)->delete();
        $this->db->table('coi_declarations')->where('procurement_id', $procId)->delete();
        $this->db->table('submissions')->where('procurement_id', $procId)->delete();
        $this->db->table('procurements')->where('id', $procId)->delete();
        $this->db->table('notices')->where('id', $noticeId)->delete();
    }

    // =========================================================================
    // SECTION 6: Award & Standstill Period (AwardController)
    // =========================================================================

    public function testAwardStandstillPeriodAndRatingLockout(): void
    {
        // Setup: Create an evaluated procurement (stage_idx = 5)
        $ref = 'TEST-AWD-' . bin2hex(random_bytes(3));
        $noticeId = (int) $this->db->table('notices')->insert([
            'reference'  => $ref,
            'slug'       => url_title($ref . '-title', '-', true),
            'title'      => 'Award Lifecycle Test',
            'org_id'     => $this->buyerOrgId,
            'closing_at' => date('Y-m-d H:i:s', time() - 7200),
            'status'     => 'published',
            'created_at' => date('Y-m-d H:i:s'),
        ], true) ? $this->db->insertID() : 0;

        $this->db->table('procurements')->insert([
            'org_id'       => $this->buyerOrgId,
            'notice_id'    => $noticeId,
            'stage_idx'    => 5, // Evaluation
            'opened_at'    => date('Y-m-d H:i:s'),
            'created_by'   => $this->officerUserId,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        $procId = (int) $this->db->insertID();

        // Create winning submission and a disqualified submission
        $this->db->table('submissions')->insert([
            'procurement_id' => $procId,
            'bidder_org_id'  => $this->bidderOrgId,
            'bidder_name'    => 'Winning Bidder Ltd',
            'total_price'    => 29500000.00,
            'disqualified'   => 0,
            'reference'      => 'SUB-WIN-01',
            'status'         => 'opened',
            'received_at'    => date('Y-m-d H:i:s'),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        $winSubId = (int) $this->db->insertID();

        $this->db->table('submissions')->insert([
            'procurement_id' => $procId,
            'bidder_org_id'  => $this->secondBidderOrgId,
            'bidder_name'    => 'Disqualified Bidder Ltd',
            'total_price'    => 22000000.00,
            'disqualified'   => 1,
            'reference'      => 'SUB-DISQ-01',
            'status'         => 'opened',
            'received_at'    => date('Y-m-d H:i:s'),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        $disqSubId = (int) $this->db->insertID();

        $awardController = new AwardController();

        // 1. Attempting to award a disqualified bid must be rejected (409 disqualified)
        $reqAwardDisq = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/award",
            'POST',
            ['submission_id' => $disqSubId, 'committee_ref' => 'MPC/2026/08/99'],
            $this->approverUserId,
            $this->buyerOrgId
        );
        $resAwardDisq = $this->executeController($awardController, 'create', [$procId], $reqAwardDisq);
        $this->assertSame(409, $resAwardDisq->getStatusCode());
        $this->assertSame('disqualified', json_decode($resAwardDisq->getBody(), true)['reason']);

        // 2. Valid award of winning bid
        $reqAward = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/award",
            'POST',
            ['submission_id' => $winSubId, 'committee_ref' => 'MPC/2026/08/101'],
            $this->approverUserId,
            $this->buyerOrgId
        );
        $resAward = $this->executeController($awardController, 'create', [$procId], $reqAward);
        $this->assertSame(201, $resAward->getStatusCode());
        $awardData = json_decode($resAward->getBody(), true)['data'];
        $this->assertSame('Winning Bidder Ltd', $awardData['supplier']);
        $this->assertSame(29500000.0, (float) $awardData['amount']);
        $this->assertSame('MPC/2026/08/101', $awardData['committee_ref']);

        // Verify standstill duration is calculated strictly server-side (7 days in future)
        $standstillUntil = strtotime($awardData['standstill_until']);
        $this->assertGreaterThan(time() + 86400 * 6, $standstillUntil);
        $this->assertLessThanOrEqual(time() + 86400 * 8, $standstillUntil);

        // Verify stage is now 6 (Award)
        $procStage6 = $this->db->table('procurements')->where('id', $procId)->get()->getFirstRow('array');
        $this->assertSame(6, (int) $procStage6['stage_idx']);

        // 3. Single award invariant: Awarding again is rejected (409 already_awarded)
        $resSecondAward = $this->executeController($awardController, 'create', [$procId], $reqAward);
        $this->assertSame(409, $resSecondAward->getStatusCode());
        $this->assertSame('already_awarded', json_decode($resSecondAward->getBody(), true)['reason']);

        // 4. Rating lockout during standstill period: Calling rate must be rejected (409 in_standstill)
        $reqRate = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/rating",
            'POST',
            ['score' => 5, 'comment' => 'Excellent work'],
            $this->approverUserId,
            $this->buyerOrgId
        );
        $resRate = $this->executeController($awardController, 'rate', [$procId], $reqRate);
        $this->assertSame(409, $resRate->getStatusCode());
        $this->assertSame('in_standstill', json_decode($resRate->getBody(), true)['reason']);

        // 5. Evidence timeline assembly: Must include created, approved, published, opened, awarded
        $reqEvidence = $this->createRequest(
            "http://localhost:8080/api/v1/authority/tenders/{$procId}/evidence",
            'GET',
            [],
            $this->approverUserId,
            $this->buyerOrgId
        );
        $resEvidence = $this->executeController($awardController, 'evidence', [$procId], $reqEvidence);
        $this->assertSame(200, $resEvidence->getStatusCode());
        $evData = json_decode($resEvidence->getBody(), true)['data'];
        $events = array_column($evData['timeline'], 'event');
        $this->assertContains('awarded', $events);

        // Cleanup
        $this->db->table('awards')->where('procurement_id', $procId)->delete();
        $this->db->table('submissions')->where('procurement_id', $procId)->delete();
        $this->db->table('procurements')->where('id', $procId)->delete();
        $this->db->table('notices')->where('id', $noticeId)->delete();
    }

    // =========================================================================
    // SECTION 7: Post-Award Contract Management (ContractController)
    // =========================================================================

    public function testPostAwardContractLifecycle(): void
    {
        // Setup: Create an awarded procurement (stage_idx = 6) with award row
        $ref = 'TEST-CON-' . bin2hex(random_bytes(3));
        $noticeId = (int) $this->db->table('notices')->insert([
            'reference'  => $ref,
            'slug'       => url_title($ref . '-title', '-', true),
            'title'      => 'Post Award Contract Test',
            'org_id'     => $this->buyerOrgId,
            'status'     => 'published',
            'created_at' => date('Y-m-d H:i:s'),
        ], true) ? $this->db->insertID() : 0;

        $this->db->table('procurements')->insert([
            'org_id'     => $this->buyerOrgId,
            'notice_id'  => $noticeId,
            'stage_idx'  => 6, // Award
            'created_by' => $this->officerUserId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $procId = (int) $this->db->insertID();

        $this->db->table('submissions')->insert([
            'procurement_id' => $procId,
            'bidder_org_id'  => $this->bidderOrgId,
            'bidder_name'    => 'Contractor Co Ltd',
            'total_price'    => 20000000.00,
            'reference'      => 'SUB-CON-01',
            'received_at'    => date('Y-m-d H:i:s'),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        $subId = (int) $this->db->insertID();

        $this->db->table('awards')->insert([
            'procurement_id'   => $procId,
            'submission_id'    => $subId,
            'supplier_org_id'  => $this->bidderOrgId,
            'amount'           => 20000000.00,
            'committee_ref'    => 'COM/2026/CON/01',
            'awarded_by'       => $this->approverUserId,
            'awarded_at'       => date('Y-m-d H:i:s'),
            'standstill_until' => date('Y-m-d H:i:s', time() - 3600), // Standstill expired
            'created_at'       => date('Y-m-d H:i:s'),
        ]);
        $awardId = (int) $this->db->insertID();

        $contractController = new ContractController();

        // 1. Create contract from awarded tender
        $reqCreateCon = $this->createRequest(
            'http://localhost:8080/api/v1/authority/contracts',
            'POST',
            [
                'procurement_id'       => $procId,
                'title'                => 'Construction Agreement for Test Project',
                'performance_security' => 2000000.00,
                'retention_pct'        => 5.0,
            ],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resCreateCon = $this->executeController($contractController, 'create', [], $reqCreateCon);
        $this->assertSame(201, $resCreateCon->getStatusCode());
        $conData = json_decode($resCreateCon->getBody(), true)['data'];
        $contractId = (int) $conData['id'];
        $this->assertSame('draft', $conData['status']);
        $this->assertSame(20000000.0, (float) $conData['value']);
        $this->assertSame($this->bidderOrgId, (int) $conData['supplier_org_id']);

        // 2. Invariant: Duplicate contract creation for same procurement is rejected (409 exists)
        $resDuplicateCon = $this->executeController($contractController, 'create', [], $reqCreateCon);
        $this->assertSame(409, $resDuplicateCon->getStatusCode());
        $this->assertSame('exists', json_decode($resDuplicateCon->getBody(), true)['reason']);

        // 3. Activation validation: Attempting activation without dates is rejected (422 validation_failed)
        $reqActivateNoDates = $this->createRequest(
            "http://localhost:8080/api/v1/authority/contracts/{$contractId}/activate",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resActivateNoDates = $this->executeController($contractController, 'activate', [$contractId], $reqActivateNoDates);
        $this->assertSame(422, $resActivateNoDates->getStatusCode());

        // Update contract with valid start and end dates
        $this->db->table('contracts')->where('id', $contractId)->update([
            'start_date' => date('Y-m-d'),
            'end_date'   => date('Y-m-d', strtotime('+6 months')),
        ]);

        // Activate contract (draft -> active)
        $resActivate = $this->executeController($contractController, 'activate', [$contractId], $reqActivateNoDates);
        $this->assertSame(200, $resActivate->getStatusCode());
        $this->assertSame('active', json_decode($resActivate->getBody(), true)['data']['status']);

        // 4. Milestone lifecycle
        $reqAddMilestone = $this->createRequest(
            "http://localhost:8080/api/v1/authority/contracts/{$contractId}/milestones",
            'POST',
            [
                'title'    => 'Foundation Inspection Completion',
                'due_date' => date('Y-m-d', strtotime('+30 days')),
                'amount'   => 5000000.00,
            ],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resAddMilestone = $this->executeController($contractController, 'addMilestone', [$contractId], $reqAddMilestone);
        $this->assertSame(201, $resAddMilestone->getStatusCode());
        $milestoneId = (int) json_decode($resAddMilestone->getBody(), true)['data']['milestone_id'];

        // Mark milestone as met
        $reqMeetMilestone = $this->createRequest(
            "http://localhost:8080/api/v1/authority/contracts/{$contractId}/milestones/{$milestoneId}/meet",
            'POST',
            [],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resMeetMilestone = $this->executeController($contractController, 'meetMilestone', [$contractId, $milestoneId], $reqMeetMilestone);
        $this->assertSame(200, $resMeetMilestone->getStatusCode());
        $this->assertSame('met', json_decode($resMeetMilestone->getBody(), true)['data']['status']);

        // 5. Contract variation: Adjust value (+ 2,500,000)
        $reqVariation = $this->createRequest(
            "http://localhost:8080/api/v1/authority/contracts/{$contractId}/variations",
            'POST',
            [
                'reason'       => 'Unforeseen sub-soil stabilization works',
                'value_change' => 2500000.00,
            ],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resVariation = $this->executeController($contractController, 'addVariation', [$contractId], $reqVariation);
        $this->assertSame(201, $resVariation->getStatusCode());
        $this->assertSame(22500000.0, (float) json_decode($resVariation->getBody(), true)['data']['new_value']);

        // 6. Contract invoices
        $reqInvoice = $this->createRequest(
            "http://localhost:8080/api/v1/authority/contracts/{$contractId}/invoices",
            'POST',
            [
                'milestone_id' => $milestoneId,
                'number'       => 'INV-TEST-001',
                'amount'       => 5000000.00,
            ],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resInvoice = $this->executeController($contractController, 'addInvoice', [$contractId], $reqInvoice);
        $this->assertSame(201, $resInvoice->getStatusCode());

        // 7. State transitions: active -> completed -> closed
        $reqComplete = $this->createRequest(
            "http://localhost:8080/api/v1/authority/contracts/{$contractId}/transition",
            'POST',
            ['status' => 'completed'],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resComplete = $this->executeController($contractController, 'transition', [$contractId], $reqComplete);
        $this->assertSame(200, $resComplete->getStatusCode());
        $this->assertSame('completed', json_decode($resComplete->getBody(), true)['data']['status']);

        $reqClose = $this->createRequest(
            "http://localhost:8080/api/v1/authority/contracts/{$contractId}/transition",
            'POST',
            ['status' => 'closed'],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resClose = $this->executeController($contractController, 'transition', [$contractId], $reqClose);
        $this->assertSame(200, $resClose->getStatusCode());
        $this->assertSame('closed', json_decode($resClose->getBody(), true)['data']['status']);

        // Illegal transition: closed cannot become active (409 wrong_state)
        $reqIllegal = $this->createRequest(
            "http://localhost:8080/api/v1/authority/contracts/{$contractId}/transition",
            'POST',
            ['status' => 'active'],
            $this->officerUserId,
            $this->buyerOrgId
        );
        $resIllegal = $this->executeController($contractController, 'transition', [$contractId], $reqIllegal);
        $this->assertSame(409, $resIllegal->getStatusCode());

        // Cleanup
        $this->db->table('contract_invoices')->where('contract_id', $contractId)->delete();
        $this->db->table('contract_variations')->where('contract_id', $contractId)->delete();
        $this->db->table('contract_milestones')->where('contract_id', $contractId)->delete();
        $this->db->table('contracts')->where('id', $contractId)->delete();
        $this->db->table('awards')->where('id', $awardId)->delete();
        $this->db->table('submissions')->where('id', $subId)->delete();
        $this->db->table('procurements')->where('id', $procId)->delete();
        $this->db->table('notices')->where('id', $noticeId)->delete();
    }
}
