<?php

namespace Tests\Unit;

use App\Libraries\Payments\PaymentGatewayService;
use CodeIgniter\Test\CIUnitTestCase;

class PaymentGatewayTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('PAYHERE_MERCHANT_ID');
        putenv('PAYHERE_MERCHANT_SECRET');
        putenv('PAYHERE_MODE');
    }

    public function testMissingSecretThrowsExceptionOnCheckout(): void
    {
        putenv('PAYHERE_MERCHANT_ID=1211149');
        putenv('PAYHERE_MERCHANT_SECRET=');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PayHere configuration missing');

        PaymentGatewayService::createPayHereCheckout([
            'order_id' => 'ORD-1234',
            'amount'   => 15000.00,
            'currency' => 'LKR',
            'email'    => 'test@tenderhub.lk',
        ]);
    }

    public function testMissingSecretFailsClosedOnWebhook(): void
    {
        putenv('PAYHERE_MERCHANT_ID=1211149');
        putenv('PAYHERE_MERCHANT_SECRET=');

        $result = PaymentGatewayService::verifyPayHereWebhook([
            'order_id'         => 'ORD-1234',
            'payhere_amount'   => '15000.00',
            'payhere_currency' => 'LKR',
            'status_code'      => '2',
            'md5sig'           => 'SOME_HASH',
        ]);

        $this->assertFalse($result, 'Webhook verification must fail closed when merchant secret is unset.');
    }

    public function testValidConfiguredSecretVerifiesAccurately(): void
    {
        $merchantId = '1211149';
        $secret     = 'super_secure_production_secret_key_999';
        putenv("PAYHERE_MERCHANT_ID={$merchantId}");
        putenv("PAYHERE_MERCHANT_SECRET={$secret}");

        $orderId  = 'ORD-TEST-99';
        $amount   = '15000.00';
        $currency = 'LKR';
        $status   = '2';

        $expectedSig = strtoupper(
            md5(
                $merchantId .
                $orderId .
                $amount .
                $currency .
                $status .
                strtoupper(md5($secret))
            )
        );

        $valid = PaymentGatewayService::verifyPayHereWebhook([
            'order_id'         => $orderId,
            'payhere_amount'   => $amount,
            'payhere_currency' => $currency,
            'status_code'      => $status,
            'md5sig'           => $expectedSig,
        ]);

        $this->assertTrue($valid, 'Valid signature must be verified successfully.');
    }

    public function testForgedSignatureIsRejected(): void
    {
        putenv('PAYHERE_MERCHANT_ID=1211149');
        putenv('PAYHERE_MERCHANT_SECRET=super_secure_production_secret_key_999');

        $rejected = PaymentGatewayService::verifyPayHereWebhook([
            'order_id'         => 'ORD-TEST-99',
            'payhere_amount'   => '15000.00',
            'payhere_currency' => 'LKR',
            'status_code'      => '2',
            'md5sig'           => 'FORGED_ATTACKER_SIGNATURE_HASH_1234567890',
        ]);

        $this->assertFalse($rejected, 'Forged hash must be rejected.');
    }
}
