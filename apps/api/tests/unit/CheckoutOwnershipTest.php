<?php

namespace Tests\Unit;

use App\Controllers\Api\V1\Payments\CheckoutController;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

class CheckoutOwnershipTest extends CIUnitTestCase
{
    private function createRequest(array $body, ?int $userId = null, ?int $orgId = null): IncomingRequest
    {
        $config = new App();
        $uri    = new URI('http://localhost:8080/api/v1/payments/checkout');
        $agent  = new UserAgent();
        $request = new IncomingRequest($config, $uri, 'php://input', $agent);

        $request->setBody(json_encode($body));
        $request->setHeader('Content-Type', 'application/json');

        if ($userId !== null) {
            $request->userId = $userId;
            $request->claims = [
                'sub' => $userId,
                'org' => $orgId ?? 0,
            ];
        }

        if ($orgId !== null) {
            $request->orgId = $orgId;
        }

        return $request;
    }

    public function testUnauthenticatedCheckoutFailsWith401(): void
    {
        $controller = new CheckoutController();
        $request = $this->createRequest(['plan' => 'monthly', 'gateway' => 'payhere'], null, null);
        $controller->initController($request, new \CodeIgniter\HTTP\Response(new App()), new \Psr\Log\NullLogger());

        $response = $controller->checkout();
        $this->assertSame(401, $response->getStatusCode(), 'Checkout without authenticated identity must return 401.');
    }

    public function testCheckoutWithoutOrgContextFailsWith403(): void
    {
        $controller = new CheckoutController();
        // User ID 2 but no org
        $request = $this->createRequest(['plan' => 'monthly', 'gateway' => 'payhere'], 2, 0);
        $controller->initController($request, new \CodeIgniter\HTTP\Response(new App()), new \Psr\Log\NullLogger());

        $response = $controller->checkout();
        $this->assertSame(403, $response->getStatusCode(), 'Checkout without org context must return 403.');
    }

    public function testAuthenticatedCheckoutAttributesToCorrectUserAndOrg(): void
    {
        $db = \Config\Database::connect('default');
        
        // Ensure test user exists
        $testUser = $db->table('users')->where('id', 2)->get()->getFirstRow('array');
        if (! $testUser) {
            $db->table('users')->insert([
                'id'            => 2,
                'org_id'        => 1,
                'name'          => 'Target User Two',
                'email'         => 'user2_test@tenderhub.lk',
                'password_hash' => 'hash',
                'role'          => 'bidder',
                'status'        => 'active',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        }

        putenv('PAYHERE_MERCHANT_ID=1211149');
        putenv('PAYHERE_MERCHANT_SECRET=test_secret_key_1234567890');

        $controller = new CheckoutController();
        $request = $this->createRequest(['plan' => 'monthly', 'gateway' => 'payhere'], 2, 1);
        $controller->initController($request, new \CodeIgniter\HTTP\Response(new App()), new \Psr\Log\NullLogger());

        $response = $controller->checkout();
        $this->assertSame(200, $response->getStatusCode(), 'Checkout must succeed for valid user.');

        $body = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('data', $body);
        $orderId = $body['data']['order_id'] ?? null;
        $this->assertNotNull($orderId);

        // Verify that in orders table, user_id is EXACTLY 2 (not 1) and org_id is 1
        $orderRow = $db->table('orders')->where('order_id', $orderId)->get()->getFirstRow('array');
        $this->assertNotNull($orderRow);
        $this->assertEquals(2, $orderRow['user_id'], 'Order must be strictly attributed to authenticated user ID 2.');
        $this->assertEquals(1, $orderRow['org_id'], 'Order must be strictly attributed to authenticated org ID 1.');

        // Clean up
        $db->table('orders')->where('order_id', $orderId)->delete();
    }
}