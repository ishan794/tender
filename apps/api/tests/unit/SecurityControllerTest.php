<?php

namespace Tests\Unit;

use App\Controllers\Api\V1\Admin\SecurityController;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

class SecurityControllerTest extends CIUnitTestCase
{
    public function testSecurityEventsSummaryExecutesCleanly(): void
    {
        $db = \Config\Database::connect('default');
        // Insert a sample security event to ensure group by query exercises rows
        $db->table('security_events')->insert([
            'kind'       => 'test_login_failure',
            'severity'   => 'low',
            'detail'     => 'Automated test event',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $controller = new SecurityController();
        $request = new IncomingRequest(new App(), Services::uri('http://example.com/api/v1/admin/security/events'), null, new UserAgent());
        $controller->initController($request, new Response(new App()), new \Psr\Log\NullLogger());

        $response = $controller->events();
        $this->assertSame(200, $response->getStatusCode(), 'Security events endpoint must return 200.');

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
        $this->assertArrayHasKey('summary_24h', $body['meta']);
        $this->assertArrayHasKey('test_login_failure', $body['meta']['summary_24h']);
        $this->assertGreaterThanOrEqual(1, $body['meta']['summary_24h']['test_login_failure']);
    }
}