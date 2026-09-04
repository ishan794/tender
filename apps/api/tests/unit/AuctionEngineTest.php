<?php

namespace Tests\Unit;

use App\Controllers\Api\V1\Authority\AuctionWorkspaceController;
use App\Controllers\Api\V1\PublicApi\AuctionController;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Validates Phase 6: Auction Engine & Lot Lifecycle (§14 of Build Blueprint Rev 3.0).
 * Covers:
 * - Lot creation parameter validation (asset classes, methods, future dates, reserve, deposit_pct)
 * - Authority publication gates and multi-tenant isolation
 * - Result recording with reserve price floor enforcement and mandatory reasons
 * - Public catalogue & detail endpoints with server-computed deposit invariance and statutory custody boundary
 */
class AuctionEngineTest extends CIUnitTestCase
{
    protected $db;
    protected int $sellerOrgId;
    protected int $otherOrgId;
    protected int $sellerUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect('default');

        // Resolve test fixtures from seed data
        $sellerOrg = $this->db->table('organisations')->where('type', 'company')->get()->getFirstRow('array');
        $this->sellerOrgId = (int) $sellerOrg['id'];

        $otherOrg = $this->db->table('organisations')->where('type', 'bidder')->get()->getFirstRow('array');
        $this->otherOrgId = (int) $otherOrg['id'];

        $sellerUser = $this->db->table('users')->where('org_id', $this->sellerOrgId)->get()->getFirstRow('array');
        $this->sellerUserId = (int) $sellerUser['id'];
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
                'nm'  => 'Test Auctioneer',
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

    public function testAuctionCreationValidation(): void
    {
        $controller = new AuctionWorkspaceController();

        // 1. Missing required field (e.g. title) -> 422 validation_failed
        $req1 = $this->createRequest(
            'http://localhost:8080/api/v1/authority/auctions',
            'POST',
            [
                'reference'   => 'AUC-2026-VAL-01',
                'closing_at'  => date('Y-m-d H:i:s', time() + 86400 * 14),
                'asset_class' => 'vehicle',
                'method'      => 'recovery',
            ],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $res1 = $this->executeController($controller, 'create', [], $req1);
        $this->assertSame(422, $res1->getStatusCode());
        $this->assertSame('validation_failed', json_decode($res1->getBody(), true)['reason']);

        // 2. Bad asset class -> 422 bad_asset_class
        $req2 = $this->createRequest(
            'http://localhost:8080/api/v1/authority/auctions',
            'POST',
            [
                'title'       => 'Toyota Hilux 2022',
                'reference'   => 'AUC-2026-VAL-02',
                'closing_at'  => date('Y-m-d H:i:s', time() + 86400 * 14),
                'asset_class' => 'spacecraft',
                'method'      => 'recovery',
            ],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $res2 = $this->executeController($controller, 'create', [], $req2);
        $this->assertSame(422, $res2->getStatusCode());
        $this->assertSame('bad_asset_class', json_decode($res2->getBody(), true)['reason']);

        // 3. Bad method -> 422 bad_method
        $req3 = $this->createRequest(
            'http://localhost:8080/api/v1/authority/auctions',
            'POST',
            [
                'title'       => 'Toyota Hilux 2022',
                'reference'   => 'AUC-2026-VAL-03',
                'closing_at'  => date('Y-m-d H:i:s', time() + 86400 * 14),
                'asset_class' => 'vehicle',
                'method'      => 'unregulated_gambling',
            ],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $res3 = $this->executeController($controller, 'create', [], $req3);
        $this->assertSame(422, $res3->getStatusCode());
        $this->assertSame('bad_method', json_decode($res3->getBody(), true)['reason']);

        // 4. Closing in the past -> 422 closing_in_past
        $req4 = $this->createRequest(
            'http://localhost:8080/api/v1/authority/auctions',
            'POST',
            [
                'title'       => 'Toyota Hilux 2022',
                'reference'   => 'AUC-2026-VAL-04',
                'closing_at'  => date('Y-m-d H:i:s', time() - 3600),
                'asset_class' => 'vehicle',
                'method'      => 'recovery',
            ],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $res4 = $this->executeController($controller, 'create', [], $req4);
        $this->assertSame(422, $res4->getStatusCode());
        $this->assertSame('closing_in_past', json_decode($res4->getBody(), true)['reason']);

        // 5. Negative reserve -> 422 bad_reserve
        $req5 = $this->createRequest(
            'http://localhost:8080/api/v1/authority/auctions',
            'POST',
            [
                'title'       => 'Toyota Hilux 2022',
                'reference'   => 'AUC-2026-VAL-05',
                'closing_at'  => date('Y-m-d H:i:s', time() + 86400 * 14),
                'asset_class' => 'vehicle',
                'method'      => 'recovery',
                'reserve'     => -50000.00,
            ],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $res5 = $this->executeController($controller, 'create', [], $req5);
        $this->assertSame(422, $res5->getStatusCode());
        $this->assertSame('bad_reserve', json_decode($res5->getBody(), true)['reason']);

        // 6. Bad deposit percentage (< 0 or > 100) -> 422 bad_deposit_pct
        $req6 = $this->createRequest(
            'http://localhost:8080/api/v1/authority/auctions',
            'POST',
            [
                'title'       => 'Toyota Hilux 2022',
                'reference'   => 'AUC-2026-VAL-06',
                'closing_at'  => date('Y-m-d H:i:s', time() + 86400 * 14),
                'asset_class' => 'vehicle',
                'method'      => 'recovery',
                'deposit_pct' => 125,
            ],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $res6 = $this->executeController($controller, 'create', [], $req6);
        $this->assertSame(422, $res6->getStatusCode());
        $this->assertSame('bad_deposit_pct', json_decode($res6->getBody(), true)['reason']);

        // 7. Successful creation -> 201
        $futureClose = date('Y-m-d H:i:s', time() + 86400 * 21);
        $req7 = $this->createRequest(
            'http://localhost:8080/api/v1/authority/auctions',
            'POST',
            [
                'title'       => 'Prime Commercial Land - Colombo 03',
                'reference'   => 'AUC-2026-TEST-VALID',
                'summary'     => 'Court-ordered parate execution of 40-perch commercial property.',
                'closing_at'  => $futureClose,
                'asset_class' => 'commercial',
                'method'      => 'parate',
                'reserve'     => 125000000.00,
                'deposit_pct' => 10.0,
                'venue'       => 'No. 45 Janadhipathi Mawatha, Colombo 01',
                'auctioneer'  => 'Schokman & Samerawickreme',
            ],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $res7 = $this->executeController($controller, 'create', [], $req7);
        $this->assertSame(201, $res7->getStatusCode());
        $body7 = json_decode($res7->getBody(), true)['data'];
        $this->assertNotEmpty($body7['id']);
        $this->assertNotEmpty($body7['notice_id']);

        $lotId = (int) $body7['id'];
        $noticeId = (int) $body7['notice_id'];

        // Verify notice in database
        $notice = $this->db->table('notices')->where('id', $noticeId)->get()->getFirstRow('array');
        $this->assertSame('auction', $notice['kind']);
        $this->assertSame('draft', $notice['status']);
        $this->assertSame(125000000.0, (float) $notice['estimated_value']);

        // Verify lot in database
        $lot = $this->db->table('auction_lots')->where('id', $lotId)->get()->getFirstRow('array');
        $this->assertSame('commercial', $lot['asset_class']);
        $this->assertSame('parate', $lot['method']);
        $this->assertSame(125000000.0, (float) $lot['reserve']);
        $this->assertSame(10.0, (float) $lot['deposit_pct']);

        // Clean up
        $this->db->table('auction_lots')->where('id', $lotId)->delete();
        $this->db->table('notices')->where('id', $noticeId)->delete();
    }

    public function testAuctionPublicationAndTenantIsolation(): void
    {
        $controller = new AuctionWorkspaceController();

        // Create an auction lot
        $futureClose = date('Y-m-d H:i:s', time() + 86400 * 30);
        $reqCreate = $this->createRequest(
            'http://localhost:8080/api/v1/authority/auctions',
            'POST',
            [
                'title'       => 'Industrial Generator 500kVA',
                'reference'   => 'AUC-2026-PUB-TEST',
                'closing_at'  => $futureClose,
                'asset_class' => 'machinery',
                'method'      => 'disposal',
                'reserve'     => 4500000.00,
                'deposit_pct' => 15.0,
            ],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $resCreate = $this->executeController($controller, 'create', [], $reqCreate);
        $lotId = (int) json_decode($resCreate->getBody(), true)['data']['id'];
        $noticeId = (int) json_decode($resCreate->getBody(), true)['data']['notice_id'];

        // 1. Cross-tenant publish attempt -> 404 not_found
        $reqWrongOrg = $this->createRequest(
            "http://localhost:8080/api/v1/authority/auctions/{$lotId}/publish",
            'POST',
            [],
            $this->sellerUserId,
            $this->otherOrgId
        );
        $resWrongOrg = $this->executeController($controller, 'publish', [$lotId], $reqWrongOrg);
        $this->assertSame(404, $resWrongOrg->getStatusCode());
        $this->assertSame('not_found', json_decode($resWrongOrg->getBody(), true)['reason']);

        // 2. Authorised publish -> 200
        $reqPub = $this->createRequest(
            "http://localhost:8080/api/v1/authority/auctions/{$lotId}/publish",
            'POST',
            [],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $resPub = $this->executeController($controller, 'publish', [$lotId], $reqPub);
        $this->assertSame(200, $resPub->getStatusCode());
        $this->assertTrue(json_decode($resPub->getBody(), true)['data']['published']);

        // Verify status in DB
        $notice = $this->db->table('notices')->where('id', $noticeId)->get()->getFirstRow('array');
        $this->assertSame('published', $notice['status']);
        $this->assertNotNull($notice['published_at']);

        // 3. Duplicate publish -> 409 already_published
        $resDupPub = $this->executeController($controller, 'publish', [$lotId], $reqPub);
        $this->assertSame(409, $resDupPub->getStatusCode());
        $this->assertSame('already_published', json_decode($resDupPub->getBody(), true)['reason']);

        // Clean up
        $this->db->table('auction_lots')->where('id', $lotId)->delete();
        $this->db->table('notices')->where('id', $noticeId)->delete();
    }

    public function testAuctionResultRecordingAndReserveFloor(): void
    {
        $controller = new AuctionWorkspaceController();

        // Create an auction lot (draft)
        $futureClose = date('Y-m-d H:i:s', time() + 86400 * 7);
        $reqCreate = $this->createRequest(
            'http://localhost:8080/api/v1/authority/auctions',
            'POST',
            [
                'title'       => 'Mitsubishi Rosa Bus 2018',
                'reference'   => 'AUC-2026-RES-TEST',
                'closing_at'  => $futureClose,
                'asset_class' => 'vehicle',
                'method'      => 'recovery',
                'reserve'     => 14000000.00,
                'deposit_pct' => 10.0,
            ],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $resCreate = $this->executeController($controller, 'create', [], $reqCreate);
        $lotId = (int) json_decode($resCreate->getBody(), true)['data']['id'];
        $noticeId = (int) json_decode($resCreate->getBody(), true)['data']['notice_id'];

        // 1. Result on unpublished draft lot -> 409 not_published
        $reqResDraft = $this->createRequest(
            "http://localhost:8080/api/v1/authority/auctions/{$lotId}/result",
            'POST',
            ['result' => 'sold', 'hammer_price' => 15000000.00],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $resDraft = $this->executeController($controller, 'result', [$lotId], $reqResDraft);
        $this->assertSame(409, $resDraft->getStatusCode());
        $this->assertSame('not_published', json_decode($resDraft->getBody(), true)['reason']);

        // Publish the auction lot
        $reqPub = $this->createRequest(
            "http://localhost:8080/api/v1/authority/auctions/{$lotId}/publish",
            'POST',
            [],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $this->executeController($controller, 'publish', [$lotId], $reqPub);

        // 2. Cross-tenant result attempt -> 404 not_found
        $reqCross = $this->createRequest(
            "http://localhost:8080/api/v1/authority/auctions/{$lotId}/result",
            'POST',
            ['result' => 'sold', 'hammer_price' => 15000000.00],
            $this->sellerUserId,
            $this->otherOrgId
        );
        $resCross = $this->executeController($controller, 'result', [$lotId], $reqCross);
        $this->assertSame(404, $resCross->getStatusCode());
        $this->assertSame('not_found', json_decode($resCross->getBody(), true)['reason']);

        // 3. Invalid result enum string -> 422 bad_result
        $reqInvalidResult = $this->createRequest(
            "http://localhost:8080/api/v1/authority/auctions/{$lotId}/result",
            'POST',
            ['result' => 'gifted_away'],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $resInvalidResult = $this->executeController($controller, 'result', [$lotId], $reqInvalidResult);
        $this->assertSame(422, $resInvalidResult->getStatusCode());
        $this->assertSame('bad_result', json_decode($resInvalidResult->getBody(), true)['reason']);

        // 4. Reserve price floor check: Hammer price below reserve -> 409 below_reserve
        // Reserve is 14,000,000; offer hammer of 12,500,000
        $reqBelowReserve = $this->createRequest(
            "http://localhost:8080/api/v1/authority/auctions/{$lotId}/result",
            'POST',
            ['result' => 'sold', 'hammer_price' => 12500000.00],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $resBelowReserve = $this->executeController($controller, 'result', [$lotId], $reqBelowReserve);
        $this->assertSame(409, $resBelowReserve->getStatusCode());
        $this->assertSame('below_reserve', json_decode($resBelowReserve->getBody(), true)['reason']);

        // 5. Withdrawn without reason note -> 422 reason_required
        $reqNoReason = $this->createRequest(
            "http://localhost:8080/api/v1/authority/auctions/{$lotId}/result",
            'POST',
            ['result' => 'withdrawn', 'result_note' => ''],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $resNoReason = $this->executeController($controller, 'result', [$lotId], $reqNoReason);
        $this->assertSame(422, $resNoReason->getStatusCode());
        $this->assertSame('reason_required', json_decode($resNoReason->getBody(), true)['reason']);

        // 6. Successful result recording: Sold at or above reserve
        $reqSold = $this->createRequest(
            "http://localhost:8080/api/v1/authority/auctions/{$lotId}/result",
            'POST',
            ['result' => 'sold', 'hammer_price' => 14750000.00],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $resSold = $this->executeController($controller, 'result', [$lotId], $reqSold);
        $this->assertSame(200, $resSold->getStatusCode());
        $soldData = json_decode($resSold->getBody(), true)['data'];
        $this->assertSame('sold', $soldData['result']);
        $this->assertSame(14750000.0, (float) $soldData['hammer_price']);

        // 7. Result lock: Overwriting a sold lot is rejected -> 409 already_sold
        $reqOverwrite = $this->createRequest(
            "http://localhost:8080/api/v1/authority/auctions/{$lotId}/result",
            'POST',
            ['result' => 'unsold'],
            $this->sellerUserId,
            $this->sellerOrgId
        );
        $resOverwrite = $this->executeController($controller, 'result', [$lotId], $reqOverwrite);
        $this->assertSame(409, $resOverwrite->getStatusCode());
        $this->assertSame('already_sold', json_decode($resOverwrite->getBody(), true)['reason']);

        // Clean up
        $this->db->table('auction_lots')->where('id', $lotId)->delete();
        $this->db->table('notices')->where('id', $noticeId)->delete();
    }

    public function testPublicAuctionCatalogueAndComputedDepositInvariance(): void
    {
        // 1. Create a draft lot and a published lot
        $controllerAuth = new AuctionWorkspaceController();
        $futureClose = date('Y-m-d H:i:s', time() + 86400 * 20);

        // Published lot
        $resPubLot = $this->executeController($controllerAuth, 'create', [], $this->createRequest(
            'http://localhost:8080/api/v1/authority/auctions',
            'POST',
            [
                'title'       => 'Residential Villa in Kandy',
                'reference'   => 'AUC-2026-PUB-CAT-01',
                'summary'     => 'Luxury villa auctioned under recovery proceedings.',
                'closing_at'  => $futureClose,
                'asset_class' => 'house',
                'method'      => 'foreclosure',
                'reserve'     => 60000000.00,
                'deposit_pct' => 10.0,
                'venue'       => 'Kandy District Court Hall',
                'auctioneer'  => 'Central Auctioneers Ltd',
            ],
            $this->sellerUserId,
            $this->sellerOrgId
        ));
        $pubLotId = (int) json_decode($resPubLot->getBody(), true)['data']['id'];
        $pubNoticeId = (int) json_decode($resPubLot->getBody(), true)['data']['notice_id'];

        // Publish it
        $this->executeController($controllerAuth, 'publish', [$pubLotId], $this->createRequest(
            "http://localhost:8080/api/v1/authority/auctions/{$pubLotId}/publish",
            'POST',
            [],
            $this->sellerUserId,
            $this->sellerOrgId
        ));

        // Draft lot (should not show in public catalogue)
        $resDraftLot = $this->executeController($controllerAuth, 'create', [], $this->createRequest(
            'http://localhost:8080/api/v1/authority/auctions',
            'POST',
            [
                'title'       => 'Draft Private Machinery',
                'reference'   => 'AUC-2026-DRAFT-HIDDEN',
                'closing_at'  => $futureClose,
                'asset_class' => 'machinery',
                'method'      => 'disposal',
                'reserve'     => 2000000.00,
                'deposit_pct' => 10.0,
            ],
            $this->sellerUserId,
            $this->sellerOrgId
        ));
        $draftLotId = (int) json_decode($resDraftLot->getBody(), true)['data']['id'];
        $draftNoticeId = (int) json_decode($resDraftLot->getBody(), true)['data']['notice_id'];

        // 2. Public Catalogue listing (AuctionController::index)
        $controllerPublic = new AuctionController();
        $reqList = $this->createRequest('http://localhost:8080/api/v1/auctions', 'GET');
        $resList = $this->executeController($controllerPublic, 'index', [], $reqList);
        $this->assertSame(200, $resList->getStatusCode());

        $listData = json_decode($resList->getBody(), true)['data'];
        $foundPublished = false;
        $foundDraft = false;
        foreach ($listData as $item) {
            if ($item['reference'] === 'AUC-2026-PUB-CAT-01') {
                $foundPublished = true;
                $this->assertSame('auction', $item['kind']);
            }
            if ($item['reference'] === 'AUC-2026-DRAFT-HIDDEN') {
                $foundDraft = true;
            }
        }
        $this->assertTrue($foundPublished, 'Published auction must be present in public catalogue');
        $this->assertFalse($foundDraft, 'Draft auction must be excluded from public catalogue');

        // 3. Public Detail (AuctionController::show)
        $pubNotice = $this->db->table('notices')->where('id', $pubNoticeId)->get()->getFirstRow('array');
        $slug = $pubNotice['slug'];

        $reqShow = $this->createRequest("http://localhost:8080/api/v1/auctions/{$slug}", 'GET');
        $resShow = $this->executeController($controllerPublic, 'show', [$slug], $reqShow);
        $this->assertSame(200, $resShow->getStatusCode());

        $detail = json_decode($resShow->getBody(), true)['data'];
        $this->assertSame('auction', $detail['kind']);
        $this->assertArrayHasKey('auction', $detail);

        $auctionLot = $detail['auction'];
        $this->assertSame('house', $auctionLot['asset_class']);
        $this->assertSame('foreclosure', $auctionLot['method']);
        $this->assertSame(60000000.0, (float) $auctionLot['reserve']);
        $this->assertSame(10.0, (float) $auctionLot['deposit_pct']);

        // Invariance: Deposit is computed strictly server-side (60,000,000 * 10% = 6,000,000)
        // Never stored twice, preventing desync between notice and deposit slip
        $this->assertSame(6000000.0, (float) $auctionLot['deposit']);

        // Statutory Custody Boundary: Verify custody note under Payment and Settlement Systems Act
        $this->assertStringContainsString('TenderHub never holds any part of a purchase price', $auctionLot['custody_note']);

        // Clean up
        $this->db->table('auction_lots')->whereIn('id', [$pubLotId, $draftLotId])->delete();
        $this->db->table('notices')->whereIn('id', [$pubNoticeId, $draftNoticeId])->delete();
    }
}
