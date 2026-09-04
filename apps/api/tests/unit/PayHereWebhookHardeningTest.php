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
 * Validates Gate 4B & 4C: PayHere webhook signature validation,
 * amount/currency integrity, state machine transitions, replay protection,
 * and resistance to paid order downgrade.
 */
class PayHereWebhookHardeningTest extends CIUnitTestCase
{
    protected $db;
    protected OrderModel $orderModel;

    protected const TEST_MERCHANT_ID     = '123456';
    protected const TEST_MERCHANT_SECRET = 'super_secret_test_key_xyz_789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect('default');
        $this->orderModel = new OrderModel();

        putenv('PAYHERE_MERCHANT_ID=' . self::TEST_MERCHANT_ID);
        putenv('PAYHERE_MERCHANT_SECRET=' . self::TEST_MERCHANT_SECRET);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('PAYHERE_MERCHANT_ID');
        putenv('PAYHERE_MERCHANT_SECRET');
    }

    private function createOrder(float $amount = 15000.00, string $currency = 'LKR', string $status = 'pending'): array
    {
        $orderId = 'TEST-PAY-' . bin2hex(random_bytes(4));
        $id = $this->orderModel->insert([
            'order_id'   => $orderId,
            'org_id'     => 1,
            'user_id'    => 1,
            'plan'       => 'monthly',
            'amount'     => $amount,
            'currency'   => $currency,
            'gateway'    => 'payhere',
            'status'     => $status,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->orderModel->find($id);
    }

    private function generateValidSignature(string $orderId, string $amount, string $currency, int $statusCode): string
    {
        return strtoupper(
            md5(
                self::TEST_MERCHANT_ID .
                $orderId .
                $amount .
                $currency .
                $statusCode .
                strtoupper(md5(self::TEST_MERCHANT_SECRET))
            )
        );
    }

    private function executeWebhook(array $payload)
    {
        $uri = new URI('http://localhost:8080/api/v1/payments/webhook/payhere');
        $request = new IncomingRequest(new App(), $uri, 'php://input', new UserAgent());
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setBody(json_encode($payload));

        $controller = new CheckoutController();
        $controller->initController($request, Services::response(), new \Psr\Log\NullLogger());
        return $controller->webhookPayHere();
    }

    public function testValidSignatureActivatesPendingOrder(): void
    {
        $order = $this->createOrder(15000.00, 'LKR', 'pending');
        $amt = number_format(15000.00, 2, '.', '');
        $sig = $this->generateValidSignature($order['order_id'], $amt, 'LKR', 2);

        $payload = [
            'merchant_id'      => self::TEST_MERCHANT_ID,
            'order_id'         => $order['order_id'],
            'payment_id'       => '320025148972',
            'payhere_amount'   => $amt,
            'payhere_currency' => 'LKR',
            'status_code'      => 2,
            'md5sig'           => $sig,
        ];

        $resp = $this->executeWebhook($payload);
        $this->assertSame(200, $resp->getStatusCode());

        $body = json_decode($resp->getBody(), true);
        $this->assertSame('paid', $body['data']['status']);
        $this->assertFalse($body['data']['idempotent']);

        // Verify order transitioned to paid in database
        $updated = $this->orderModel->find($order['id']);
        $this->assertSame('paid', $updated['status']);
        $this->assertSame('320025148972', $updated['transaction_id']);

        // Verify organization subscription was advanced
        $org = $this->db->table('organisations')->where('id', 1)->get()->getFirstRow('array');
        $this->assertSame('business', $org['plan']);
        $this->assertSame('active', $org['sub_status']);

        // Verify ledger record created in payments
        $payment = $this->db->table('payments')->where('slip_ref', '320025148972')->get()->getFirstRow('array');
        $this->assertNotNull($payment);
        $this->assertSame('approved', $payment['state']);

        // Clean up
        $this->orderModel->delete($order['id']);
        $this->db->table('payments')->where('slip_ref', '320025148972')->delete();
    }

    public function testForgedSignatureIsRejectedWith400(): void
    {
        $order = $this->createOrder(15000.00, 'LKR', 'pending');
        $amt = number_format(15000.00, 2, '.', '');

        $payload = [
            'merchant_id'      => self::TEST_MERCHANT_ID,
            'order_id'         => $order['order_id'],
            'payment_id'       => '320025148972',
            'payhere_amount'   => $amt,
            'payhere_currency' => 'LKR',
            'status_code'      => 2,
            'md5sig'           => 'FORGED_ATTACKER_SIGNATURE_XYZ',
        ];

        $resp = $this->executeWebhook($payload);
        $this->assertSame(400, $resp->getStatusCode());

        $body = json_decode($resp->getBody(), true);
        $this->assertSame('invalid_signature', $body['reason']);

        // Order must remain pending
        $check = $this->orderModel->find($order['id']);
        $this->assertSame('pending', $check['status']);

        // Clean up
        $this->orderModel->delete($order['id']);
    }

    public function testMissingSecretFailsClosedWith400(): void
    {
        putenv('PAYHERE_MERCHANT_SECRET=');

        $order = $this->createOrder(15000.00, 'LKR', 'pending');
        $amt = number_format(15000.00, 2, '.', '');
        $sig = $this->generateValidSignature($order['order_id'], $amt, 'LKR', 2);

        $payload = [
            'merchant_id'      => self::TEST_MERCHANT_ID,
            'order_id'         => $order['order_id'],
            'payment_id'       => '320025148972',
            'payhere_amount'   => $amt,
            'payhere_currency' => 'LKR',
            'status_code'      => 2,
            'md5sig'           => $sig,
        ];

        $resp = $this->executeWebhook($payload);
        $this->assertSame(400, $resp->getStatusCode());

        $body = json_decode($resp->getBody(), true);
        $this->assertSame('invalid_signature', $body['reason']);

        // Clean up
        $this->orderModel->delete($order['id']);
    }

    public function testAmountMismatchIsRejectedWith400(): void
    {
        // Order requires 15,000.00
        $order = $this->createOrder(15000.00, 'LKR', 'pending');

        // Attacker pays 5,000.00 and computes valid signature for 5,000.00
        $underpaidAmt = number_format(5000.00, 2, '.', '');
        $sig = $this->generateValidSignature($order['order_id'], $underpaidAmt, 'LKR', 2);

        $payload = [
            'merchant_id'      => self::TEST_MERCHANT_ID,
            'order_id'         => $order['order_id'],
            'payment_id'       => '320025148972',
            'payhere_amount'   => $underpaidAmt,
            'payhere_currency' => 'LKR',
            'status_code'      => 2,
            'md5sig'           => $sig,
        ];

        $resp = $this->executeWebhook($payload);
        $this->assertSame(400, $resp->getStatusCode());

        $body = json_decode($resp->getBody(), true);
        $this->assertSame('amount_mismatch', $body['reason']);

        // Order must remain pending
        $check = $this->orderModel->find($order['id']);
        $this->assertSame('pending', $check['status']);

        // Clean up
        $this->orderModel->delete($order['id']);
    }

    public function testCurrencyMismatchIsRejectedWith400(): void
    {
        $order = $this->createOrder(15000.00, 'LKR', 'pending');
        $amt = number_format(15000.00, 2, '.', '');
        // Signed for USD instead of order's LKR
        $sig = $this->generateValidSignature($order['order_id'], $amt, 'USD', 2);

        $payload = [
            'merchant_id'      => self::TEST_MERCHANT_ID,
            'order_id'         => $order['order_id'],
            'payment_id'       => '320025148972',
            'payhere_amount'   => $amt,
            'payhere_currency' => 'USD',
            'status_code'      => 2,
            'md5sig'           => $sig,
        ];

        $resp = $this->executeWebhook($payload);
        $this->assertSame(400, $resp->getStatusCode());

        $body = json_decode($resp->getBody(), true);
        $this->assertSame('currency_mismatch', $body['reason']);

        // Clean up
        $this->orderModel->delete($order['id']);
    }

    public function testNonExistentOrderReturns404(): void
    {
        $fakeOrderId = 'NONEXISTENT-ORDER-999';
        $amt = number_format(15000.00, 2, '.', '');
        $sig = $this->generateValidSignature($fakeOrderId, $amt, 'LKR', 2);

        $payload = [
            'merchant_id'      => self::TEST_MERCHANT_ID,
            'order_id'         => $fakeOrderId,
            'payment_id'       => '320025148972',
            'payhere_amount'   => $amt,
            'payhere_currency' => 'LKR',
            'status_code'      => 2,
            'md5sig'           => $sig,
        ];

        $resp = $this->executeWebhook($payload);
        $this->assertSame(404, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $this->assertSame('order_not_found', $body['reason']);
    }

    public function testDuplicateCallbackIsIdempotent(): void
    {
        $order = $this->createOrder(15000.00, 'LKR', 'pending');
        $amt = number_format(15000.00, 2, '.', '');
        $sig = $this->generateValidSignature($order['order_id'], $amt, 'LKR', 2);

        $payload = [
            'merchant_id'      => self::TEST_MERCHANT_ID,
            'order_id'         => $order['order_id'],
            'payment_id'       => 'TXN_IDEMPOTENT_1',
            'payhere_amount'   => $amt,
            'payhere_currency' => 'LKR',
            'status_code'      => 2,
            'md5sig'           => $sig,
        ];

        // First callback -> activates order
        $resp1 = $this->executeWebhook($payload);
        $this->assertSame(200, $resp1->getStatusCode());
        $body1 = json_decode($resp1->getBody(), true);
        $this->assertFalse($body1['data']['idempotent']);

        // Record organisation renews_at timestamp
        $org1 = $this->db->table('organisations')->where('id', 1)->get()->getFirstRow('array');
        $renewsAt1 = $org1['renews_at'];

        // Second duplicate callback -> must be idempotent
        $resp2 = $this->executeWebhook($payload);
        $this->assertSame(200, $resp2->getStatusCode());
        $body2 = json_decode($resp2->getBody(), true);
        $this->assertTrue($body2['data']['idempotent'], 'Duplicate callback must be flagged as idempotent.');

        // renews_at must NOT be extended a second time
        $org2 = $this->db->table('organisations')->where('id', 1)->get()->getFirstRow('array');
        $this->assertSame($renewsAt1, $org2['renews_at'], 'Subscription duration must not be double-extended.');

        // Clean up
        $this->orderModel->delete($order['id']);
        $this->db->table('payments')->where('slip_ref', 'TXN_IDEMPOTENT_1')->delete();
    }

    public function testConflictingCallbackCannotDowngradePaidOrder(): void
    {
        // Order is already paid
        $order = $this->createOrder(15000.00, 'LKR', 'paid');
        $amt = number_format(15000.00, 2, '.', '');

        // Attacker or late callback sends failure status (-2) with valid signature
        $sig = $this->generateValidSignature($order['order_id'], $amt, 'LKR', -2);

        $payload = [
            'merchant_id'      => self::TEST_MERCHANT_ID,
            'order_id'         => $order['order_id'],
            'payment_id'       => 'FAIL_TXN_999',
            'payhere_amount'   => $amt,
            'payhere_currency' => 'LKR',
            'status_code'      => -2,
            'md5sig'           => $sig,
        ];

        $resp = $this->executeWebhook($payload);
        $this->assertSame(409, $resp->getStatusCode());

        $body = json_decode($resp->getBody(), true);
        $this->assertSame('cannot_downgrade_paid_order', $body['reason']);

        // Order must strictly remain paid
        $check = $this->orderModel->find($order['id']);
        $this->assertSame('paid', $check['status']);

        // Clean up
        $this->orderModel->delete($order['id']);
    }

    public function testFailedCallbackTransitionsPendingToFailed(): void
    {
        $order = $this->createOrder(15000.00, 'LKR', 'pending');
        $amt = number_format(15000.00, 2, '.', '');
        $sig = $this->generateValidSignature($order['order_id'], $amt, 'LKR', -2);

        $payload = [
            'merchant_id'      => self::TEST_MERCHANT_ID,
            'order_id'         => $order['order_id'],
            'payment_id'       => 'FAIL_TXN_001',
            'payhere_amount'   => $amt,
            'payhere_currency' => 'LKR',
            'status_code'      => -2,
            'md5sig'           => $sig,
        ];

        $resp = $this->executeWebhook($payload);
        $this->assertSame(200, $resp->getStatusCode());

        $body = json_decode($resp->getBody(), true);
        $this->assertSame('failed', $body['data']['status']);

        // Order in DB must be failed
        $check = $this->orderModel->find($order['id']);
        $this->assertSame('failed', $check['status']);

        // Clean up
        $this->orderModel->delete($order['id']);
    }
}
