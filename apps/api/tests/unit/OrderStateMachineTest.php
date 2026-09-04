<?php

namespace Tests\Unit;

use App\Models\OrderModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Validates Gate 4A: Order state machine transitions,
 * terminal state protection, and rejection of illegal state changes.
 */
class OrderStateMachineTest extends CIUnitTestCase
{
    protected OrderModel $orders;
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect('default');
        $this->orders = new OrderModel();
    }

    private function createTestOrder(string $status = OrderModel::STATUS_PENDING): array
    {
        $orderId = 'TEST-ORD-' . bin2hex(random_bytes(4));
        $id = $this->orders->insert([
            'order_id'   => $orderId,
            'org_id'     => 1,
            'user_id'    => 1,
            'plan'       => 'monthly',
            'amount'     => 15000.00,
            'currency'   => 'LKR',
            'gateway'    => 'payhere',
            'status'     => $status,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->orders->find($id);
    }

    public function testPendingToPaidTransition(): void
    {
        $order = $this->createTestOrder(OrderModel::STATUS_PENDING);
        $res = $this->orders->transition($order['order_id'], OrderModel::STATUS_PAID, [
            'transaction_id' => 'PAY-12345',
        ]);

        $this->assertTrue($res['changed']);
        $this->assertFalse($res['idempotent']);
        $this->assertSame(OrderModel::STATUS_PAID, $res['order']['status']);
        $this->assertSame('PAY-12345', $res['order']['transaction_id']);

        // Clean up
        $this->orders->delete($order['id']);
    }

    public function testPendingToFailedTransition(): void
    {
        $order = $this->createTestOrder(OrderModel::STATUS_PENDING);
        $res = $this->orders->transition($order['order_id'], OrderModel::STATUS_FAILED);

        $this->assertTrue($res['changed']);
        $this->assertSame(OrderModel::STATUS_FAILED, $res['order']['status']);

        // Clean up
        $this->orders->delete($order['id']);
    }

    public function testPendingToExpiredTransition(): void
    {
        $order = $this->createTestOrder(OrderModel::STATUS_PENDING);
        $res = $this->orders->transition($order['order_id'], OrderModel::STATUS_EXPIRED);

        $this->assertTrue($res['changed']);
        $this->assertSame(OrderModel::STATUS_EXPIRED, $res['order']['status']);

        // Clean up
        $this->orders->delete($order['id']);
    }

    public function testPaidOrderTransitionIsIdempotent(): void
    {
        $order = $this->createTestOrder(OrderModel::STATUS_PAID);
        $res = $this->orders->transition($order['order_id'], OrderModel::STATUS_PAID);

        $this->assertFalse($res['changed'], 'Re-transitioning to same status must not change record.');
        $this->assertTrue($res['idempotent']);
        $this->assertSame(OrderModel::STATUS_PAID, $res['order']['status']);

        // Clean up
        $this->orders->delete($order['id']);
    }

    public function testPaidCannotBeDowngradedToPending(): void
    {
        $order = $this->createTestOrder(OrderModel::STATUS_PAID);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Illegal order state transition/i');

        try {
            $this->orders->transition($order['order_id'], OrderModel::STATUS_PENDING);
        } finally {
            $this->orders->delete($order['id']);
        }
    }

    public function testPaidCannotBeDowngradedToFailed(): void
    {
        $order = $this->createTestOrder(OrderModel::STATUS_PAID);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Illegal order state transition/i');

        try {
            $this->orders->transition($order['order_id'], OrderModel::STATUS_FAILED);
        } finally {
            $this->orders->delete($order['id']);
        }
    }

    public function testFailedCannotTransitionToPaid(): void
    {
        $order = $this->createTestOrder(OrderModel::STATUS_FAILED);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Illegal order state transition/i');

        try {
            $this->orders->transition($order['order_id'], OrderModel::STATUS_PAID);
        } finally {
            $this->orders->delete($order['id']);
        }
    }

    public function testExpiredCannotTransitionToPaid(): void
    {
        $order = $this->createTestOrder(OrderModel::STATUS_EXPIRED);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Illegal order state transition/i');

        try {
            $this->orders->transition($order['order_id'], OrderModel::STATUS_PAID);
        } finally {
            $this->orders->delete($order['id']);
        }
    }
}
