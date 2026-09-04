<?php

namespace Tests\Unit;

use App\Controllers\Api\V1\PublicApi\TransparencyController;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

class TransparencyControllerTest extends CIUnitTestCase
{
    public function testTransparencyAggregatesExecuteCleanly(): void
    {
        $controller = new TransparencyController();
        $request = new IncomingRequest(new App(), Services::uri('http://example.com/api/v1/transparency'), null, new UserAgent());
        $controller->initController($request, new Response(new App()), new \Psr\Log\NullLogger());

        $response = $controller->index();
        $this->assertSame(200, $response->getStatusCode(), 'Transparency endpoint must return 200.');

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $data = $body['data'];

        $this->assertArrayHasKey('published_notices', $data);
        $this->assertArrayHasKey('total_awarded_value', $data);
        $this->assertArrayHasKey('organisations', $data);
        $this->assertArrayHasKey('suppliers', $data);
        $this->assertArrayHasKey('open_notices', $data);
        $this->assertArrayHasKey('closed_notices', $data);
        $this->assertArrayHasKey('awards_by_district', $data);

        $this->assertGreaterThanOrEqual(0, $data['published_notices']);
        $this->assertGreaterThanOrEqual(0, $data['total_awarded_value']);
        $this->assertIsArray($data['awards_by_district']);
    }
}