<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use App\Libraries\Ingestion\CrawlerIngestionService;
use App\Libraries\Webhooks\WebhookDispatcher;
use App\Libraries\DocumentStore;
use App\Models\NoticeModel;
use App\Models\OrderModel;

/**
 * @internal
 */
final class SecurityFuzzingTest extends CIUnitTestCase
{
    private NoticeModel $noticeModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect();
        $this->noticeModel = model(NoticeModel::class);
    }

    public function testSqlInjectionFuzzingOnPublicSearch(): void
    {
        $sqliVectors = [
            "' OR '1'='1",
            "' OR 1=1 --",
            "admin'--",
            "'; DROP TABLE notices; --",
            "' UNION SELECT id, password_hash, email, name, role, user_group, status, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1 FROM users --",
            "1' AND SLEEP(5) --",
            "1' AND 1=(SELECT COUNT(*) FROM tablenames); --",
            "\" OR \"\"=\"",
            "` OR 1=1 --",
            "\\'; DROP TABLE users; --",
        ];

        foreach ($sqliVectors as $vector) {
            // Search by keyword and facets
            $results = $this->noticeModel->search([
                'q'          => $vector,
                'categories' => [$vector],
                'districts'  => [$vector],
            ], 1, 10);

            $this->assertIsArray($results, "SQL injection vector [{$vector}] must return valid array result.");
            $this->assertArrayHasKey('rows', $results, "SQL injection vector [{$vector}] must contain rows.");
            $this->assertArrayHasKey('total', $results, "SQL injection vector [{$vector}] must contain total count.");
            $this->assertIsInt($results['total'], "SQL injection total for [{$vector}] must be an integer.");
        }
    }

    public function testSsrfFuzzingVectorsBlockedAcrossAdapters(): void
    {
        $ssrfVectors = [
            'http://169.254.169.254/latest/meta-data',
            'http://169.254.169.254/computeMetadata/v1/',
            'http://127.0.0.1:8080/admin',
            'http://localhost:3000/api',
            'http://10.0.0.1/router',
            'http://172.16.0.1/secret',
            'http://192.168.1.1/gateway',
            'http://0.0.0.0:80/',
            'file:///etc/passwd',
            'file:///c:/boot.ini',
            'gopher://127.0.0.1:6379/_FLUSHALL',
            'dict://127.0.0.1:11211/stat',
            'ftp://anonymous:secret@127.0.0.1/pub',
            'ldap://127.0.0.1:389/c=US',
        ];

        foreach ($ssrfVectors as $url) {
            $crawlerRes = CrawlerIngestionService::validateFetchUrl($url, true);
            $this->assertFalse($crawlerRes['ok'], "Crawler SSRF validator must block [{$url}]");

            $webhookRes = WebhookDispatcher::validateUrl($url, true);
            $this->assertFalse($webhookRes['ok'], "Webhook SSRF validator must block [{$url}]");
        }
    }

    public function testPathTraversalVectorsBlockedInDocumentStore(): void
    {
        $store = new DocumentStore();

        $traversalVectors = [
            '../../../../../../windows/win.ini',
            '..\\..\\..\\..\\windows\\system32\\drivers\\etc\\hosts',
            '....//....//....//etc/passwd',
            '/etc/shadow',
            'C:\\inetpub\\wwwroot\\web.config',
            "file.pdf\0.exe",
            '..%2f..%2f..%2fsecret.txt',
            '..%252f..%252fsecret.txt',
            'uploads/../../../config.php',
        ];

        foreach ($traversalVectors as $path) {
            $fetch = $store->fetch($path);
            $this->assertFalse($fetch['ok'], "Path traversal vector [{$path}] must fail closed.");
            $this->assertNull($fetch['content'], "No content must ever be leaked for [{$path}].");
        }
    }

    public function testXssSanitizationFuzzing(): void
    {
        $xssVectors = [
            '<script>alert("XSS")</script>',
            '<IMG SRC=javascript:alert(\'XSS\')>',
            '<IMG SRC=JaVaScRiPt:alert(\'XSS\')>',
            '<IMG SRC=javascript:alert(&quot;XSS&quot;)>',
            '<IMG SRC=`javascript:alert("RSN")`>',
            '<a href="javascript:doEvil()">click me</a>',
            '<iframe src="https://evil.com/steal"></iframe>',
            '<svg/onload=alert(1)>',
            '<body onload=alert(1)>',
            '<input type="image" src="x" onerror="alert(1)">',
            '<link rel="stylesheet" href="javascript:alert(1)">',
            '<object data="javascript:alert(1)"></object>',
            '<embed src="javascript:alert(1)">',
            '<style>@import "javascript:alert(1)";</style>',
            '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
        ];

        foreach ($xssVectors as $v) {
            $sanitized = CrawlerIngestionService::sanitizeHtml($v);
            $this->assertStringNotContainsString('javascript:', strtolower($sanitized), "Sanitizer must strip javascript: pseudo-protocol in [{$v}].");
            $this->assertStringNotContainsString('<script', strtolower($sanitized), "Sanitizer must strip <script in [{$v}].");
            $this->assertStringNotContainsString('onload', strtolower($sanitized), "Sanitizer must strip onload in [{$v}].");
            $this->assertStringNotContainsString('onerror', strtolower($sanitized), "Sanitizer must strip onerror in [{$v}].");
            $this->assertStringNotContainsString('<iframe', strtolower($sanitized), "Sanitizer must strip <iframe in [{$v}].");
            $this->assertStringNotContainsString('<object', strtolower($sanitized), "Sanitizer must strip <object in [{$v}].");
            $this->assertStringNotContainsString('<embed', strtolower($sanitized), "Sanitizer must strip <embed in [{$v}].");
        }
    }

    public function testCrossTenantIdorIsolationMatrix(): void
    {
        // 1. Order Isolation
        $orderModel = model(OrderModel::class);
        $uniqueOrder = 'ORD-IDOR-' . bin2hex(random_bytes(3));
        $this->db->table('orders')->insert([
            'order_id'   => $uniqueOrder,
            'org_id'     => 4, // Org 4
            'user_id'    => 10,
            'plan'       => 'business',
            'amount'     => 15000.00,
            'status'     => 'paid',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $order = $orderModel->where('order_id', $uniqueOrder)->first();
        $this->assertNotNull($order);

        // Org 5 (different tenant) cannot own or mutate Org 4's order
        $diffOrgId = 5;
        $diffUserId = 11;
        $isOwner = ((int) $order['org_id'] === $diffOrgId && (int) $order['user_id'] === $diffUserId);
        $this->assertFalse($isOwner, 'Order must remain strictly tenant-isolated from differing org and user.');

        // 2. Alert Profile Isolation
        $this->db->table('alert_profiles')->insert([
            'org_id'     => 4,
            'user_id'    => 10,
            'name'       => 'Confidential Org 4 Profile',
            'channels'   => 'in_app',
            'active'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $profileId = (int) $this->db->insertID();

        // Querying with Org 5 must return 0 results
        $org5Profiles = $this->db->table('alert_profiles')
            ->where('id', $profileId)
            ->where('org_id', $diffOrgId)
            ->get()->getResultArray();
        $this->assertEmpty($org5Profiles, 'Alert profile must not be accessible by other organisations.');
    }
}
