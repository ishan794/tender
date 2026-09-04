<?php

namespace Tests\Unit;

use App\Controllers\Api\V1\Authority\SaleController;
use App\Controllers\Api\V1\Files\FileController;
use App\Controllers\Api\V1\Member\MemberController;
use App\Libraries\DocumentStore;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Validates Phase 8: Document Security & Integrity.
 * Covers:
 * - Content-addressed blob store, SHA-256 calculation, and deduplication
 * - Multi-version document history and supersession
 * - Antivirus/malware scanning boundary (EICAR, executables, scripts, magic bytes)
 * - HMAC-SHA256 signed download URLs with tampering and expiration guards
 * - Tenant isolation and bidder purchase prerequisites for fee-bearing tenders
 * - Legal hold retention blocks on deletion and versioning
 * - Content integrity check on read (fail-closed on corrupt/tampered disk blob)
 * - Event Ledger auditing for all document actions (upload, version, delete, download)
 */
class DocumentSecurityTest extends CIUnitTestCase
{
    protected $db;
    protected int $buyerOrgId;
    protected int $otherOrgId;
    protected int $bidderOrgId;
    protected int $buyerUserId;
    protected int $otherUserId;
    protected int $bidderUserId;
    protected int $procId;
    protected int $noticeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = db_connect();
        $this->clearUploadFile();

        // 1. Create Buyer Org & User
        $this->db->table('organisations')->insert([
            'name' => 'Doc Test Buyer Org', 'slug' => 'doc-buyer-' . uniqid(),
            'type' => 'company', 'plan' => 'enterprise', 'sub_status' => 'active',
            'verify_state' => 'verified', 'standstill_days' => 7,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->buyerOrgId = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'org_id' => $this->buyerOrgId, 'email' => 'buyer_' . uniqid() . '@example.com',
            'password_hash' => password_hash('Secret123!', PASSWORD_DEFAULT),
            'role' => 'company', 'name' => 'Buyer Officer', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->buyerUserId = (int) $this->db->insertID();

        // 2. Create Other Org & User (for cross-tenant tests)
        $this->db->table('organisations')->insert([
            'name' => 'Doc Test Other Org', 'slug' => 'doc-other-' . uniqid(),
            'type' => 'company', 'plan' => 'enterprise', 'sub_status' => 'active',
            'verify_state' => 'verified', 'standstill_days' => 7,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->otherOrgId = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'org_id' => $this->otherOrgId, 'email' => 'other_' . uniqid() . '@example.com',
            'password_hash' => password_hash('Secret123!', PASSWORD_DEFAULT),
            'role' => 'company', 'name' => 'Other Officer', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->otherUserId = (int) $this->db->insertID();

        // 3. Create Bidder Org & User
        $this->db->table('organisations')->insert([
            'name' => 'Doc Test Bidder Org', 'slug' => 'doc-bidder-' . uniqid(),
            'type' => 'bidder', 'plan' => 'business', 'sub_status' => 'active',
            'verify_state' => 'verified', 'standstill_days' => 7,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->bidderOrgId = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'org_id' => $this->bidderOrgId, 'email' => 'bidder_' . uniqid() . '@example.com',
            'password_hash' => password_hash('Secret123!', PASSWORD_DEFAULT),
            'role' => 'bidder', 'name' => 'Bidder User', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->bidderUserId = (int) $this->db->insertID();

        // 4. Create Notice & Procurement with document fee
        $this->db->table('notices')->insert([
            'kind' => 'tender', 'reference' => 'REF-DOC-' . rand(100, 999),
            'slug' => 'doc-notice-' . uniqid(), 'title' => 'Document Security Test Tender',
            'org_id' => $this->buyerOrgId, 'document_fee' => 5000.00,
            'closing_at' => date('Y-m-d H:i:s', time() + 86400),
            'opening_at' => date('Y-m-d H:i:s', time() + 86400),
            'status' => 'published', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->noticeId = (int) $this->db->insertID();

        $this->db->table('procurements')->insert([
            'notice_id' => $this->noticeId, 'org_id' => $this->buyerOrgId,
            'stage_idx' => 2, 'created_by' => $this->buyerUserId,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->procId = (int) $this->db->insertID();
    }

    protected function tearDown(): void
    {
        $this->clearUploadFile();
        $this->db->table('legal_holds')->where('entity_type', 'procurement')->where('entity_id', $this->procId)->delete();
        $this->db->table('legal_holds')->where('entity_type', 'notice')->where('entity_id', $this->noticeId)->delete();
        $this->db->table('doc_purchases')->where('procurement_id', $this->procId)->delete();
        $this->db->table('document_downloads')->whereIn('notice_document_id', function ($builder) {
            return $builder->select('id')->from('notice_documents')->where('notice_id', $this->noticeId);
        })->delete();
        $this->db->table('document_versions')->whereIn('notice_document_id', function ($builder) {
            return $builder->select('id')->from('notice_documents')->where('notice_id', $this->noticeId);
        })->delete();
        $this->db->table('notice_documents')->where('notice_id', $this->noticeId)->delete();
        $this->db->table('event_ledger')->where('entity_type', 'procurement')->where('entity_id', $this->procId)->delete();
        $this->db->table('event_ledger')->where('entity_type', 'notice')->where('entity_id', $this->noticeId)->delete();
        $this->db->table('procurements')->where('id', $this->procId)->delete();
        $this->db->table('notices')->whereIn('org_id', [$this->buyerOrgId, $this->otherOrgId, $this->bidderOrgId])->delete();
        $this->db->table('users')->whereIn('id', [$this->buyerUserId, $this->otherUserId, $this->bidderUserId])->delete();
        $this->db->table('organisations')->whereIn('id', [$this->buyerOrgId, $this->otherOrgId, $this->bidderOrgId])->delete();
        parent::tearDown();
    }

    private function setUploadFile(string $content, string $clientName, string $mime): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'testdoc');
        file_put_contents($tmp, $content);

        $_FILES = [
            'file' => [
                'name'     => $clientName,
                'type'     => $mime,
                'size'     => strlen($content),
                'tmp_name' => $tmp,
                'error'    => UPLOAD_ERR_OK,
            ],
        ];
        service('superglobals')->setFilesArray($_FILES);
    }

    private function clearUploadFile(): void
    {
        $_FILES = [];
        service('superglobals')->setFilesArray([]);
    }

    private function makeRequest(string $method, string $path, array $claims = [], array $get = [], array $post = []): IncomingRequest
    {
        $config  = new App();
        $uriStr  = 'http://example.com' . $path;
        if ($get !== []) {
            $uriStr .= '?' . http_build_query($get);
        }
        $uri     = new URI($uriStr);
        $request = new IncomingRequest($config, $uri, null, new UserAgent());
        $request->setMethod($method);
        if ($claims !== []) {
            $request->claims = $claims;
            $request->userId = (int) ($claims['sub'] ?? 0);
            $request->orgId  = (int) ($claims['org'] ?? 0);
        }
        if ($post !== []) {
            $request->setGlobal('post', $post);
        }
        if ($get !== []) {
            $request->setGlobal('get', $get);
        }
        Services::injectServerRequest($request);

        return $request;
    }

    public function testDocumentUploadContentAddressedStorageAndLedgerAudit(): void
    {
        $pdfContent = "%PDF-1.4\nTest tender document specification content " . uniqid() . "\n%%EOF";
        $this->setUploadFile($pdfContent, 'specification.pdf', 'application/pdf');

        $req = $this->makeRequest('POST', "/api/v1/authority/tenders/{$this->procId}/documents", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company', 'grp' => 'company',
        ], [], ['kind' => 'bidding', 'reason' => 'Initial spec']);

        $ctrl = new SaleController();
        $ctrl->initController($req, Services::response(), Services::logger());
        $res = $ctrl->upload($this->procId);

        $this->assertEquals(201, $res->getStatusCode());
        $body = json_decode($res->getBody(), true);
        $expectedSha = hash('sha256', $pdfContent);
        $this->assertEquals($expectedSha, $body['data']['sha256']);
        $this->assertFalse($body['data']['deduped']);

        $docId = (int) $body['data']['id'];

        // Verify content-addressed file exists in storage
        $store = new DocumentStore();
        $relPath = $store->pathFor($expectedSha, 'pdf');
        $this->assertTrue($store->exists($relPath));
        $this->assertTrue($store->verifyContent($relPath, $expectedSha));

        // Verify version 1 recorded
        $ver = $this->db->table('document_versions')->where('notice_document_id', $docId)->get()->getFirstRow('array');
        $this->assertNotNull($ver);
        $this->assertEquals(1, (int) $ver['version']);
        $this->assertEquals(0, (int) $ver['superseded']);
        $this->assertEquals($expectedSha, $ver['sha256']);

        // Verify Event Ledger recorded doc.uploaded
        $ledger = service('eventLedger')->forEntity('procurement', $this->procId);
        $hasUploadEvent = false;
        foreach ($ledger as $e) {
            if ($e['event_type'] === 'doc.uploaded') {
                $hasUploadEvent = true;
                $payload = json_decode($e['payload'], true);
                $this->assertEquals($docId, $payload['doc_id']);
                $this->assertEquals($expectedSha, $payload['sha256']);
            }
        }
        $this->assertTrue($hasUploadEvent, 'doc.uploaded event must be recorded in Event Ledger');

        // Test deduplication on re-upload
        $this->setUploadFile($pdfContent, 'specification_copy.pdf', 'application/pdf');
        $reqDup = $this->makeRequest('POST', "/api/v1/authority/tenders/{$this->procId}/documents", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company', 'grp' => 'company',
        ], [], ['kind' => 'bidding']);
        $ctrlDup = new SaleController();
        $ctrlDup->initController($reqDup, Services::response(), Services::logger());
        $resDup = $ctrlDup->upload($this->procId);
        $this->assertEquals(201, $resDup->getStatusCode());
        $bodyDup = json_decode($resDup->getBody(), true);
        $this->assertTrue($bodyDup['data']['deduped'], 'Re-uploading identical content must be marked deduped');
    }

    public function testDocumentVersioningLifecycleAndSupersession(): void
    {
        // 1. Initial upload (v1)
        $contentV1 = "%PDF-1.4\nVersion 1 Content\n%%EOF";
        $this->setUploadFile($contentV1, 'spec_v1.pdf', 'application/pdf');
        $reqV1 = $this->makeRequest('POST', "/api/v1/authority/tenders/{$this->procId}/documents", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company', 'grp' => 'company',
        ], [], ['kind' => 'bidding']);
        $ctrl = new SaleController();
        $ctrl->initController($reqV1, Services::response(), Services::logger());
        $resV1 = $ctrl->upload($this->procId);
        $docId = (int) json_decode($resV1->getBody(), true)['data']['id'];

        // 2. Upload new version (v2)
        $contentV2 = "%PDF-1.4\nVersion 2 Content with clarifications\n%%EOF";
        $this->setUploadFile($contentV2, 'spec_v2.pdf', 'application/pdf');
        $reqV2 = $this->makeRequest('POST', "/api/v1/authority/tenders/{$this->procId}/documents/{$docId}/version", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company', 'grp' => 'company',
        ], [], ['reason' => 'Addendum 1 revisions']);

        $ctrlV2 = new SaleController();
        $ctrlV2->initController($reqV2, Services::response(), Services::logger());
        $resV2 = $ctrlV2->uploadVersion($this->procId, $docId);
        $this->assertEquals(201, $resV2->getStatusCode());
        $bodyV2 = json_decode($resV2->getBody(), true);
        $this->assertEquals(2, $bodyV2['data']['version']);
        $shaV2 = hash('sha256', $contentV2);
        $this->assertEquals($shaV2, $bodyV2['data']['sha256']);

        // 3. Verify database version tracking
        $versions = $this->db->table('document_versions')
            ->where('notice_document_id', $docId)
            ->orderBy('version', 'ASC')
            ->get()->getResultArray();
        $this->assertCount(2, $versions);
        $this->assertEquals(1, (int) $versions[0]['version']);
        $this->assertEquals(1, (int) $versions[0]['superseded'], 'Version 1 must be marked superseded');
        $this->assertEquals(2, (int) $versions[1]['version']);
        $this->assertEquals(0, (int) $versions[1]['superseded'], 'Version 2 must be active');

        // 4. Verify notice_documents points to v2
        $docRow = model('App\Models\NoticeDocumentModel')->find($docId);
        $this->assertEquals($shaV2, $docRow['sha256']);
        $this->assertEquals('spec_v2.pdf', $docRow['name']);

        // 5. Verify Event Ledger recorded doc.version_added
        $ledger = service('eventLedger')->forEntity('procurement', $this->procId);
        $hasVersionEvent = false;
        foreach ($ledger as $e) {
            if ($e['event_type'] === 'doc.version_added') {
                $hasVersionEvent = true;
                $payload = json_decode($e['payload'], true);
                $this->assertEquals(2, $payload['version']);
                $this->assertEquals($shaV2, $payload['sha256']);
            }
        }
        $this->assertTrue($hasVersionEvent);
    }

    public function testMalwareAndProhibitedFileRejection(): void
    {
        // 1. Standard EICAR test string
        $eicar = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        $this->setUploadFile($eicar, 'eicar.pdf', 'application/pdf');
        $req1 = $this->makeRequest('POST', "/api/v1/authority/tenders/{$this->procId}/documents", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company',
        ]);
        $ctrl1 = new SaleController();
        $ctrl1->initController($req1, Services::response(), Services::logger());
        $res1 = $ctrl1->upload($this->procId);
        $this->assertEquals(422, $res1->getStatusCode());
        $this->assertEquals('malware_detected', json_decode($res1->getBody(), true)['reason']);

        // 2. Windows PE Executable disguised as PDF (MZ header)
        $mzHeader = "MZ\x90\x00\x03\x00\x00\x00BinaryPayload";
        $this->setUploadFile($mzHeader, 'virus.pdf', 'application/pdf');
        $req2 = $this->makeRequest('POST', "/api/v1/authority/tenders/{$this->procId}/documents", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company',
        ]);
        $ctrl2 = new SaleController();
        $ctrl2->initController($req2, Services::response(), Services::logger());
        $res2 = $ctrl2->upload($this->procId);
        $this->assertEquals(422, $res2->getStatusCode());
        $this->assertEquals('malware_detected', json_decode($res2->getBody(), true)['reason']);

        // 3. Shell script masquerading as PDF (#!/ header)
        $shHeader = "#!/bin/bash\nrm -rf /";
        $this->setUploadFile($shHeader, 'exploit.pdf', 'application/pdf');
        $req3 = $this->makeRequest('POST', "/api/v1/authority/tenders/{$this->procId}/documents", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company',
        ]);
        $ctrl3 = new SaleController();
        $ctrl3->initController($req3, Services::response(), Services::logger());
        $res3 = $ctrl3->upload($this->procId);
        $this->assertEquals(422, $res3->getStatusCode());
        $this->assertEquals('malware_detected', json_decode($res3->getBody(), true)['reason']);

        // 4. PHP Script tag
        $phpHeader = "<?php system(\$_GET['cmd']);";
        $this->setUploadFile($phpHeader, 'webshell.pdf', 'application/pdf');
        $req4 = $this->makeRequest('POST', "/api/v1/authority/tenders/{$this->procId}/documents", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company',
        ]);
        $ctrl4 = new SaleController();
        $ctrl4->initController($req4, Services::response(), Services::logger());
        $res4 = $ctrl4->upload($this->procId);
        $this->assertEquals(422, $res4->getStatusCode());
        $this->assertEquals('malware_detected', json_decode($res4->getBody(), true)['reason']);

        // 5. Disallowed extension (.exe)
        $exeFile = 'Random binary content';
        $this->setUploadFile($exeFile, 'setup.exe', 'application/x-msdownload');
        $req5 = $this->makeRequest('POST', "/api/v1/authority/tenders/{$this->procId}/documents", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company',
        ]);
        $ctrl5 = new SaleController();
        $ctrl5->initController($req5, Services::response(), Services::logger());
        $res5 = $ctrl5->upload($this->procId);
        $this->assertEquals(422, $res5->getStatusCode());
        $this->assertEquals('bad_type', json_decode($res5->getBody(), true)['reason']);
    }

    public function testSignedDownloadUrlTamperingAndExpiration(): void
    {
        // 1. Upload valid document
        $pdfContent = "%PDF-1.4\nConfidential Tender Specs\n%%EOF";
        $this->setUploadFile($pdfContent, 'confidential.pdf', 'application/pdf');
        $reqUpload = $this->makeRequest('POST', "/api/v1/authority/tenders/{$this->procId}/documents", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company',
        ]);
        $saleCtrl = new SaleController();
        $saleCtrl->initController($reqUpload, Services::response(), Services::logger());
        $resUpload = $saleCtrl->upload($this->procId);
        $docId = (int) json_decode($resUpload->getBody(), true)['data']['id'];

        $fileCtrl = new FileController();

        // 2. Mint valid link (expires in 300s)
        $exp = time() + 300;
        $sig = DocumentStore::sign($docId, $this->buyerUserId, $exp);

        // 3. Valid download request -> 200 OK with security headers
        $reqValid = $this->makeRequest('GET', "/api/v1/files/documents/{$docId}", [], [
            'u' => $this->buyerUserId, 'e' => $exp, 's' => $sig,
        ]);
        $fileCtrl->initController($reqValid, Services::response(), Services::logger());
        $resValid = $fileCtrl->document($docId);

        $this->assertEquals(200, $resValid->getStatusCode());
        $this->assertEquals($pdfContent, $resValid->getBody());
        $this->assertEquals('attachment; filename="confidential.pdf"', $resValid->getHeaderLine('Content-Disposition'));
        $this->assertEquals('nosniff', $resValid->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('private', $resValid->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('no-store', $resValid->getHeaderLine('Cache-Control'));

        // Verify download logging
        $dlCount = $this->db->table('document_downloads')->where('notice_document_id', $docId)->countAllResults();
        $this->assertEquals(1, $dlCount);

        // 4. Expired link -> 403 invalid_link
        $expiredTime = time() - 10;
        $expiredSig = DocumentStore::sign($docId, $this->buyerUserId, $expiredTime);
        $reqExp = $this->makeRequest('GET', "/api/v1/files/documents/{$docId}", [], [
            'u' => $this->buyerUserId, 'e' => $expiredTime, 's' => $expiredSig,
        ]);
        $fileCtrlExp = new FileController();
        $fileCtrlExp->initController($reqExp, Services::response(), Services::logger());
        $resExp = $fileCtrlExp->document($docId);
        $this->assertEquals(403, $resExp->getStatusCode());
        $this->assertEquals('invalid_link', json_decode($resExp->getBody(), true)['reason']);

        // 5. Tampered user ID in query string -> 403 invalid_link
        $reqTamperUser = $this->makeRequest('GET', "/api/v1/files/documents/{$docId}", [], [
            'u' => $this->otherUserId, 'e' => $exp, 's' => $sig,
        ]);
        $fileCtrlTamper = new FileController();
        $fileCtrlTamper->initController($reqTamperUser, Services::response(), Services::logger());
        $resTamperUser = $fileCtrlTamper->document($docId);
        $this->assertEquals(403, $resTamperUser->getStatusCode());

        // 6. Tampered expiry in query string -> 403 invalid_link
        $reqTamperExp = $this->makeRequest('GET', "/api/v1/files/documents/{$docId}", [], [
            'u' => $this->buyerUserId, 'e' => $exp + 500, 's' => $sig,
        ]);
        $fileCtrlTamperExp = new FileController();
        $fileCtrlTamperExp->initController($reqTamperExp, Services::response(), Services::logger());
        $resTamperExp = $fileCtrlTamperExp->document($docId);
        $this->assertEquals(403, $resTamperExp->getStatusCode());

        // 7. Forged HMAC signature -> 403 invalid_link
        $reqForged = $this->makeRequest('GET', "/api/v1/files/documents/{$docId}", [], [
            'u' => $this->buyerUserId, 'e' => $exp, 's' => 'deadbeefcafebabe000000000000000000000000000000000000000000000000',
        ]);
        $fileCtrlForged = new FileController();
        $fileCtrlForged->initController($reqForged, Services::response(), Services::logger());
        $resForged = $fileCtrlForged->document($docId);
        $this->assertEquals(403, $resForged->getStatusCode());
    }

    public function testTenantIsolationAndBidderPurchaseGating(): void
    {
        // 1. Upload bidding document
        $pdfContent = "%PDF-1.4\nOfficial Bidding Dossier\n%%EOF";
        $this->setUploadFile($pdfContent, 'bidding_dossier.pdf', 'application/pdf');
        $reqUpload = $this->makeRequest('POST', "/api/v1/authority/tenders/{$this->procId}/documents", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company',
        ], [], ['kind' => 'bidding']);
        $saleCtrl = new SaleController();
        $saleCtrl->initController($reqUpload, Services::response(), Services::logger());
        $resUpload = $saleCtrl->upload($this->procId);
        $docId = (int) json_decode($resUpload->getBody(), true)['data']['id'];

        // 2. Cross-tenant buy-side operations rejected with 404
        $reqCross = $this->makeRequest('DELETE', "/api/v1/authority/tenders/{$this->procId}/documents/{$docId}", [
            'sub' => $this->otherUserId, 'org' => $this->otherOrgId, 'role' => 'company',
        ]);
        $saleCtrlCross = new SaleController();
        $saleCtrlCross->initController($reqCross, Services::response(), Services::logger());
        $resCross = $saleCtrlCross->deleteDocument($this->procId, $docId);
        $this->assertEquals(404, $resCross->getStatusCode());

        // 3. Bidder requests download URL without purchasing fee-bearing tender -> 403 documents_not_purchased
        $memberCtrl = new MemberController();
        $reqBidderNoBuy = $this->makeRequest('GET', "/api/v1/me/notices/{$this->noticeId}/documents/{$docId}/url", [
            'sub' => $this->bidderUserId, 'org' => $this->bidderOrgId, 'role' => 'bidder',
        ]);
        $memberCtrl->initController($reqBidderNoBuy, Services::response(), Services::logger());
        $resBidderNoBuy = $memberCtrl->documentUrl($this->noticeId, $docId);
        $this->assertEquals(403, $resBidderNoBuy->getStatusCode());
        $this->assertEquals('documents_not_purchased', json_decode($resBidderNoBuy->getBody(), true)['reason']);

        // 4. Bidder purchases document
        $reqBuy = $this->makeRequest('POST', "/api/v1/me/tenders/{$this->procId}/buy-documents", [
            'sub' => $this->bidderUserId, 'org' => $this->bidderOrgId, 'role' => 'bidder',
        ], [], ['amount' => 5000.00]);
        $saleCtrlBuy = new SaleController();
        $saleCtrlBuy->initController($reqBuy, Services::response(), Services::logger());
        $resBuy = $saleCtrlBuy->buyDocuments($this->procId);
        $this->assertEquals(201, $resBuy->getStatusCode());

        // 5. Bidder requests download URL after purchase -> 200 OK with valid signed link
        $reqBidderBought = $this->makeRequest('GET', "/api/v1/me/notices/{$this->noticeId}/documents/{$docId}/url", [
            'sub' => $this->bidderUserId, 'org' => $this->bidderOrgId, 'role' => 'bidder',
        ]);
        $memberCtrlBought = new MemberController();
        $memberCtrlBought->initController($reqBidderBought, Services::response(), Services::logger());
        $resBidderBought = $memberCtrlBought->documentUrl($this->noticeId, $docId);
        $this->assertEquals(200, $resBidderBought->getStatusCode());
        $this->assertNotEmpty(json_decode($resBidderBought->getBody(), true)['data']['url']);
    }

    public function testLegalHoldEnforcementOnDocumentMutation(): void
    {
        $pdfContent = "%PDF-1.4\nAudit Trail Protected Doc\n%%EOF";
        $this->setUploadFile($pdfContent, 'audit_doc.pdf', 'application/pdf');
        $reqUpload = $this->makeRequest('POST', "/api/v1/authority/tenders/{$this->procId}/documents", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company',
        ]);
        $saleCtrl = new SaleController();
        $saleCtrl->initController($reqUpload, Services::response(), Services::logger());
        $resUpload = $saleCtrl->upload($this->procId);
        $docId = (int) json_decode($resUpload->getBody(), true)['data']['id'];

        // Place legal hold on the procurement
        $this->db->table('legal_holds')->insert([
            'entity_type' => 'procurement', 'entity_id' => $this->procId,
            'reason' => 'High Court Appeal in progress', 'created_by' => $this->buyerUserId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $holdId = (int) $this->db->insertID();

        // 1. Attempt to delete document -> 423 legal_hold
        $reqDel = $this->makeRequest('DELETE', "/api/v1/authority/tenders/{$this->procId}/documents/{$docId}", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company',
        ]);
        $saleCtrlDel = new SaleController();
        $saleCtrlDel->initController($reqDel, Services::response(), Services::logger());
        $resDel = $saleCtrlDel->deleteDocument($this->procId, $docId);
        $this->assertEquals(423, $resDel->getStatusCode());
        $this->assertEquals('legal_hold', json_decode($resDel->getBody(), true)['reason']);

        // 2. Attempt to upload new version -> 423 legal_hold
        $newContent = "%PDF-1.4\nAttempted Overwrite\n%%EOF";
        $this->setUploadFile($newContent, 'new.pdf', 'application/pdf');
        $reqVer = $this->makeRequest('POST', "/api/v1/authority/tenders/{$this->procId}/documents/{$docId}/version", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company',
        ]);
        $saleCtrlVer = new SaleController();
        $saleCtrlVer->initController($reqVer, Services::response(), Services::logger());
        $resVer = $saleCtrlVer->uploadVersion($this->procId, $docId);
        $this->assertEquals(423, $resVer->getStatusCode());

        // 3. Release legal hold
        $this->db->table('legal_holds')->where('id', $holdId)->update(['released_at' => date('Y-m-d H:i:s')]);

        // 4. Delete document succeeds after release
        $resDelAfter = $saleCtrlDel->deleteDocument($this->procId, $docId);
        $this->assertEquals(200, $resDelAfter->getStatusCode());

        // Verify document deleted from database
        $this->assertNull(model('App\Models\NoticeDocumentModel')->find($docId));

        // Verify doc.deleted recorded in Event Ledger
        $ledger = service('eventLedger')->forEntity('procurement', $this->procId);
        $hasDeletedEvent = false;
        foreach ($ledger as $e) {
            if ($e['event_type'] === 'doc.deleted') {
                $hasDeletedEvent = true;
            }
        }
        $this->assertTrue($hasDeletedEvent);
    }

    public function testContentAddressedIntegrityOnRead(): void
    {
        $pdfContent = "%PDF-1.4\nOriginal Unmodified Document\n%%EOF";
        $this->setUploadFile($pdfContent, 'original.pdf', 'application/pdf');
        $reqUpload = $this->makeRequest('POST', "/api/v1/authority/tenders/{$this->procId}/documents", [
            'sub' => $this->buyerUserId, 'org' => $this->buyerOrgId, 'role' => 'company',
        ]);
        $saleCtrl = new SaleController();
        $saleCtrl->initController($reqUpload, Services::response(), Services::logger());
        $resUpload = $saleCtrl->upload($this->procId);
        $docId = (int) json_decode($resUpload->getBody(), true)['data']['id'];

        $docRow = model('App\Models\NoticeDocumentModel')->find($docId);
        $store = new DocumentStore();
        $absPath = $store->absolute($docRow['path']);

        $exp = time() + 300;
        $sig = DocumentStore::sign($docId, $this->buyerUserId, $exp);
        $req = $this->makeRequest('GET', "/api/v1/files/documents/{$docId}", [], [
            'u' => $this->buyerUserId, 'e' => $exp, 's' => $sig,
        ]);
        $fileCtrl = new FileController();
        $fileCtrl->initController($req, Services::response(), Services::logger());

        // Normal read succeeds
        $resNormal = $fileCtrl->document($docId);
        $this->assertEquals(200, $resNormal->getStatusCode());

        // Tamper with file on disk directly
        file_put_contents($absPath, "%PDF-1.4\nTAMPERED CORRUPTED CONTENT\n%%EOF");

        // Request tampered file -> 500 integrity_check_failed
        $fileCtrlTampered = new FileController();
        $fileCtrlTampered->initController($req, Services::response(), Services::logger());
        $resTampered = $fileCtrlTampered->document($docId);

        $this->assertEquals(500, $resTampered->getStatusCode());
        $this->assertEquals('integrity_check_failed', json_decode($resTampered->getBody(), true)['reason']);

        // Restore original content -> 200 OK again
        file_put_contents($absPath, $pdfContent);
        $fileCtrlRestored = new FileController();
        $fileCtrlRestored->initController($req, Services::response(), Services::logger());
        $resRestored = $fileCtrlRestored->document($docId);
        $this->assertEquals(200, $resRestored->getStatusCode());
    }
}
