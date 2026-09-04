<?php

namespace App\Libraries\Payments;

/**
 * PaymentGatewayService
 * Unified payment driver supporting PayHere (Sri Lanka LKR) and Stripe (USD/International).
 */
class PaymentGatewayService
{
    /**
     * Generates PayHere Checkout Form Parameters with MD5/SHA256 signature.
     * PayHere Signature = strtoupper(md5(merchant_id + order_id + amountFormatted + currency + strtoupper(md5(merchant_secret))))
     */
    public static function createPayHereCheckout(array $data): array
    {
        $merchantId     = (string) (getenv('PAYHERE_MERCHANT_ID') ?: '');
        $merchantSecret = (string) (getenv('PAYHERE_MERCHANT_SECRET') ?: '');
        if ($merchantId === '' || $merchantSecret === '') {
            throw new \RuntimeException('PayHere configuration missing: PAYHERE_MERCHANT_ID and PAYHERE_MERCHANT_SECRET must be set in environment.');
        }
        $isSandbox      = (getenv('PAYHERE_MODE') ?: 'sandbox') === 'sandbox';

        $orderId   = $data['order_id'];
        $amount    = number_format((float) $data['amount'], 2, '.', '');
        $currency  = $data['currency'] ?? 'LKR';

        $hash = strtoupper(
            md5(
                $merchantId . 
                $orderId . 
                $amount . 
                $currency . 
                strtoupper(md5($merchantSecret))
            )
        );

        $gatewayUrl = $isSandbox 
            ? 'https://sandbox.payhere.lk/pay/checkout'
            : 'https://www.payhere.lk/pay/checkout';

        return [
            'gateway'     => 'payhere',
            'action_url'  => $gatewayUrl,
            'params'      => [
                'merchant_id'   => $merchantId,
                'return_url'    => $data['return_url'] ?? 'https://tenderhub.lk/subscription/success',
                'cancel_url'    => $data['cancel_url'] ?? 'https://tenderhub.lk/subscription/cancel',
                'notify_url'    => $data['notify_url'] ?? 'https://tenderhub.lk/api/v1/payments/webhook/payhere',
                'order_id'      => $orderId,
                'items'         => $data['item_name'] ?? 'TenderHub Subscription',
                'currency'      => $currency,
                'amount'        => $amount,
                'first_name'    => $data['first_name'] ?? 'Subscriber',
                'last_name'     => $data['last_name'] ?? 'User',
                'email'         => $data['email'],
                'phone'         => $data['phone'] ?? '0770000000',
                'address'       => 'Colombo',
                'city'          => 'Colombo',
                'country'       => 'Sri Lanka',
                'hash'          => $hash,
            ]
        ];
    }

    /**
     * Verifies inbound PayHere Webhook notification signature.
     */
    public static function verifyPayHereWebhook(array $post): bool
    {
        $merchantId     = (string) (getenv('PAYHERE_MERCHANT_ID') ?: '');
        $merchantSecret = (string) (getenv('PAYHERE_MERCHANT_SECRET') ?: '');
        if ($merchantId === '' || $merchantSecret === '') {
            return false; // Fail-closed when PayHere secret is not configured
        }

        // Validate merchant_id matches environment configuration if supplied
        if (isset($post['merchant_id']) && (string) $post['merchant_id'] !== $merchantId) {
            return false;
        }

        $orderId        = $post['order_id'] ?? '';
        $payhereAmount  = $post['payhere_amount'] ?? '';
        $payhereCurrency= $post['payhere_currency'] ?? '';
        $statusCode     = $post['status_code'] ?? '';
        $receivedMd5Sig = $post['md5sig'] ?? '';

        $localMd5Sig = strtoupper(
            md5(
                $merchantId . 
                $orderId . 
                $payhereAmount . 
                $payhereCurrency . 
                $statusCode . 
                strtoupper(md5($merchantSecret))
            )
        );

        return hash_equals($localMd5Sig, $receivedMd5Sig);
    }

    /**
     * Creates Stripe Hosted Checkout Session.
     */
    public static function createStripeSession(array $data): array
    {
        $stripeKey = getenv('STRIPE_SECRET_KEY') ?: 'sk_test_mock';
        $orderId   = $data['order_id'];
        $amount    = (int) ($data['amount'] * 100); // Cents
        $currency  = strtolower($data['currency'] ?? 'usd');

        // When in live production with Stripe SDK, call \Stripe\Checkout\Session::create
        // In local/mock mode, return valid session structure
        return [
            'gateway'    => 'stripe',
            'session_id' => 'cs_test_' . bin2hex(random_bytes(16)),
            'action_url' => 'https://checkout.stripe.com/pay/cs_test_' . $orderId,
            'params'     => [
                'order_id' => $orderId,
                'amount'   => $amount,
                'currency' => $currency,
            ]
        ];
    }
}
