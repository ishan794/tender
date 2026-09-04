<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use App\Models\OrderModel;

/**
 * @internal
 */
final class SubscriptionLifecycleTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect();
    }

    public function testOrderCreationAndStateMachineTransitions(): void
    {
        $orderModel = model(OrderModel::class);
        $orderId = 'SUB-TEST-' . bin2hex(random_bytes(4));

        $this->db->table('orders')->insert([
            'order_id'   => $orderId,
            'org_id'     => 4,
            'user_id'    => 10,
            'plan'       => 'annual',
            'amount'     => 150000.00,
            'currency'   => 'LKR',
            'gateway'    => 'payhere',
            'status'     => OrderModel::STATUS_PENDING,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $order = $orderModel->where('order_id', $orderId)->first();
        $this->assertNotNull($order);
        $this->assertSame(OrderModel::STATUS_PENDING, $order['status']);

        // Transition pending -> paid
        $orderModel->transition($orderId, OrderModel::STATUS_PAID, [
            'transaction_id' => 'TXN-99998888',
        ]);

        $paidOrder = $orderModel->where('order_id', $orderId)->first();
        $this->assertSame(OrderModel::STATUS_PAID, $paidOrder['status']);
        $this->assertSame('TXN-99998888', $paidOrder['transaction_id']);

        // Invalid downgrade paid -> pending must throw LogicException
        $this->expectException(\LogicException::class);
        $orderModel->transition($orderId, OrderModel::STATUS_PENDING);
    }

    public function testRefundProcessingRevertsSubscriptionAndAuditsEvent(): void
    {
        $orderId = 'SUB-REFUND-' . bin2hex(random_bytes(3));
        $orgId = 4;

        $this->db->table('orders')->insert([
            'order_id'   => $orderId,
            'org_id'     => $orgId,
            'user_id'    => 10,
            'plan'       => 'annual',
            'amount'     => 150000.00,
            'currency'   => 'LKR',
            'gateway'    => 'payhere',
            'status'     => OrderModel::STATUS_PAID,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $orderRow = $this->db->table('orders')->where('order_id', $orderId)->get()->getFirstRow('array');

        // Apply refund
        $reason = 'Customer requested full refund within guarantee window';
        $this->db->table('orders')->where('order_id', $orderId)->update([
            'status'        => 'refunded',
            'refund_reason' => $reason,
            'refunded_at'   => date('Y-m-d H:i:s'),
        ]);

        // Revert org
        $this->db->table('organisations')->where('id', $orgId)->update([
            'plan'       => 'free',
            'sub_status' => 'none',
        ]);

        // Audit in EventLedger
        service('eventLedger')->record('order', (int) $orderRow['id'], 'order.refunded', "Order {$orderId} refunded: {$reason}", [
            'order_id' => $orderId,
            'amount'   => 150000.00,
            'org_id'   => $orgId,
            'reason'   => $reason,
        ]);

        // Verify order status
        $updated = $this->db->table('orders')->where('order_id', $orderId)->get()->getFirstRow('array');
        $this->assertSame('refunded', $updated['status']);
        $this->assertSame($reason, $updated['refund_reason']);
        $this->assertNotNull($updated['refunded_at']);

        // Verify EventLedger
        $event = $this->db->table('event_ledger')
            ->where('entity_type', 'order')
            ->where('entity_id', (int) $orderRow['id'])
            ->where('event_type', 'order.refunded')
            ->get()->getFirstRow('array');
        $this->assertNotNull($event);
        $this->assertStringContainsString("Order {$orderId} refunded", $event['summary']);
    }

    public function testBankTransferPaymentLifecycle(): void
    {
        $orgId = 4;
        $userId = 10;

        // Insert claimed payment
        $this->db->table('payments')->insert([
            'org_id'     => $orgId,
            'user_id'    => $userId,
            'plan'       => 'business',
            'term'       => 'annual',
            'amount'     => 150000.00,
            'method'     => 'bank_transfer',
            'bank'       => 'Commercial Bank of Ceylon',
            'slip_ref'   => 'SLIP-' . rand(100000, 999999),
            'paid_on'    => date('Y-m-d'),
            'channel'    => 'email',
            'state'      => 'claimed',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $paymentId = (int) $this->db->insertID();

        // 1. Confirm payment
        $this->db->table('payments')->where('id', $paymentId)->update([
            'state'       => 'confirmed',
            'reviewed_by' => 1,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        $this->db->table('organisations')->where('id', $orgId)->update([
            'plan'       => 'business',
            'sub_status' => 'active',
            'renews_at'  => date('Y-m-d H:i:s', strtotime('+12 months')),
        ]);

        service('eventLedger')->record('payment', $paymentId, 'payment.confirmed', "Payment #{$paymentId} confirmed", [
            'payment_id' => $paymentId,
            'org_id'     => $orgId,
        ]);

        $confirmed = $this->db->table('payments')->where('id', $paymentId)->get()->getFirstRow('array');
        $this->assertSame('confirmed', $confirmed['state']);

        $org = $this->db->table('organisations')->where('id', $orgId)->get()->getFirstRow('array');
        $this->assertSame('business', $org['plan']);
        $this->assertSame('active', $org['sub_status']);
    }
}
