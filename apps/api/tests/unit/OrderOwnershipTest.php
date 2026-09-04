<?php

namespace Tests\Unit;

use App\Controllers\Api\V1\Payments\CheckoutController;
use App\Models\OrderModel;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Validates Gate 4D: Order ownership and cross-tenant isolation.
 * Proves that User A / Org A cannot access or mutate Order B / Org B.
 */
class OrderOwnershipTest extends CIUnitTestCase
{
    protected OrderModel $orderModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderModel = new OrderModel();
    }

    private function createOrder(int $userId, int $orgId): array
    {
        $orderId = 'ORD-OWN-' . bin2hex(random_bytes(4));
        $id = $this->orderModel->insert([
            'order_id'   => $orderId,
            'org_id'     => $orgId,
            'user_id'    => $userId,
            'plan'       => 'monthly',
            'amount'     => 15000.00,
            'currency'   => 'LKR',
            'gateway'    => 'payhere',
            'status'     => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->orderModel->find($id);
    }

    private function createRequest(string $orderId, ?int $userId = null, ?int $orgId = null): IncomingRequest
    {
        $uri = new URI('http://localhost:8080/api/v1/payments/orders/' . $orderId);
        $request = new IncomingRequest(new App(), $uri, 'php://input', new UserAgent());
        $request->setMethod('GET');
        $request->setHeader('Accept', 'application/json');

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

    private function executeShow(string $orderId, ?int $userId, ?int $orgId)
    {
        $controller = new CheckoutController();
        $request = $this->createRequest($orderId, $userId, $orgId);
        $controller->initController($request, Services::response(), new \Psr\Log\NullLogger());
        return $controller->show($orderId);
    }

    public function testUnauthenticatedOrderAccessReturns401(): void
    {
        $order = $this->createOrder(1, 1);
        $resp = $this->executeShow($order['order_id'], null, null);

        $this->assertSame(401, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $this->assertSame('unauthenticated', $body['reason']);

        // Clean up
        $this->orderModel->delete($order['id']);
    }

    public function testOrderAccessWithoutOrgContextReturns403(): void
    {
        $order = $this->createOrder(1, 1);
        // User authenticated but orgId is 0 / absent
        $resp = $this->executeShow($order['order_id'], 1, 0);

        $this->assertSame(403, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $this->assertSame('no_org_context', $body['reason']);

        // Clean up
        $this->orderModel->delete($order['id']);
    }

    public function testLegitimateOwnerCanAccessOrder(): void
    {
        $order = $this->createOrder(2, 2);
        // Request from legitimate User 2 in Org 2
        $resp = $this->executeShow($order['order_id'], 2, 2);

        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $this->assertSame($order['order_id'], $body['data']['order_id']);
        $this->assertSame(2, (int) $body['data']['user_id']);
        $this->assertSame(2, (int) $body['data']['org_id']);

        // Clean up
        $this->orderModel->delete($order['id']);
    }

    public function testCrossOrgOrderAccessIsForbiddenWith403(): void
    {
        // Order belongs to User 2 in Org 2
        $order = $this->createOrder(2, 2);

        // Attacker is User 1 in Org 1 attempting to view Org 2's order
        $resp = $this->executeShow($order['order_id'], 1, 1);

        $this->assertSame(403, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $this->assertSame('forbidden_order_access', $body['reason']);

        // Clean up
        $this->orderModel->delete($order['id']);
    }

    public function testCrossUserSameOrgOrderAccessIsForbiddenWith403(): void
    {
        // Order belongs to User 2 in Org 2
        $order = $this->createOrder(2, 2);

        // Different user in same organisation (User 3 in Org 2)
        $resp = $this->executeShow($order['order_id'], 3, 2);

        $this->assertSame(403, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $this->assertSame('forbidden_order_access', $body['reason']);

        // Clean up
        $this->orderModel->delete($order['id']);
    }

    public function testNonExistentOrderReturns404(): void
    {
        $resp = $this->executeShow('NONEXISTENT-ORD-999', 1, 1);

        $this->assertSame(404, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $this->assertSame('order_not_found', $body['reason']);
    }
}
