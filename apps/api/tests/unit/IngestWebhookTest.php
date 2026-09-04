<?php

namespace Tests\Unit;

use App\Controllers\Api\V1\Admin\IngestWebhookController;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

class IngestWebhookTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('INGEST_SECRET_KEY');
    }

    private function createRequest(string $ingestKey = '', ?array $body = null): IncomingRequest
    {
        $config = new App();
        $uri    = new URI('http://localhost:8080/api/v1/admin/ingest/push');
        $agent  = new UserAgent();
        $request = new IncomingRequest($config, $uri, 'php://input', $agent);

        if ($ingestKey !== '') {
            $request->setHeader('X-Ingest-Key', $ingestKey);
        }

        if ($body !== null) {
            $request->setBody(json_encode($body));
            $request->setHeader('Content-Type', 'application/json');
        }

        return $request;
    }

    public function testMissingEnvironmentSecretRejectsWebhook(): void
    {
        putenv('INGEST_SECRET_KEY=');

        $controller = new IngestWebhookController();
        $request = $this->createRequest('tenderhub_ingest_secret_2026', [['title' => 'Test Tender']]);
        $controller->initController($request, new \CodeIgniter\HTTP\Response(new App()), new \Psr\Log\NullLogger());

        $response = $controller->push();
        $this->assertSame(401, $response->getStatusCode(), 'Missing environment key must fail closed with 401.');
    }

    public function testInvalidSecretKeyIsRejected(): void
    {
        putenv('INGEST_SECRET_KEY=production_secret_key_888');

        $controller = new IngestWebhookController();
        $request = $this->createRequest('WRONG_ATTACKER_KEY', [['title' => 'Test Tender']]);
        $controller->initController($request, new \CodeIgniter\HTTP\Response(new App()), new \Psr\Log\NullLogger());

        $response = $controller->push();
        $this->assertSame(401, $response->getStatusCode(), 'Invalid ingest key must be rejected with 401.');
    }

    public function testMissingSecretHeaderIsRejected(): void
    {
        putenv('INGEST_SECRET_KEY=production_secret_key_888');

        $controller = new IngestWebhookController();
        $request = $this->createRequest('', [['title' => 'Test Tender']]);
        $controller->initController($request, new \CodeIgniter\HTTP\Response(new App()), new \Psr\Log\NullLogger());

        $response = $controller->push();
        $this->assertSame(401, $response->getStatusCode(), 'Missing header must be rejected with 401.');
    }

    public function testValidSecretAuthenticatesSuccessfully(): void
    {
        putenv('INGEST_SECRET_KEY=production_secret_key_888');

        $controller = new IngestWebhookController();
        // Empty payload to test that it passed auth and reached body validation (status 422)
        $request = $this->createRequest('production_secret_key_888', []);
        $controller->initController($request, new \CodeIgniter\HTTP\Response(new App()), new \Psr\Log\NullLogger());

        $response = $controller->push();
        $this->assertSame(422, $response->getStatusCode(), 'Valid key must authenticate and proceed to payload validation.');
    }
}