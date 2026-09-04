<?php

namespace App\Controllers\Api\V1\Payments;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Payments\PaymentGatewayService;

class CheckoutController extends BaseApiController
{
    /**
     * POST /api/v1/payments/checkout
     * Initiates online card checkout session for subscriptions.
     */
    public function checkout()
    {
        $in = $this->body();
        $rules = [
            'plan'    => 'required|in_list[monthly,annual,single]',
            'gateway' => 'permit_empty|in_list[payhere,stripe]',
        ];

        if (! $this->validateData($in, $rules)) {
            return problem(422, 'validation_failed', 'Select a valid subscription plan.', ['errors' => $this->validator->getErrors()]);
        }

        $plan    = $in['plan'];
        $gateway = $in['gateway'] ?? 'payhere';
        $userId = (int) ($this->request->userId ?? ($this->request->claims['sub'] ?? 0));
        $orgId  = (int) ($this->request->orgId ?? ($this->request->claims['org'] ?? 0));

        if ($userId <= 0) {
            return problem(401, 'unauthenticated', 'Sign in to initiate a subscription checkout.');
        }

        if ($orgId <= 0) {
            return problem(403, 'no_org_context', 'An organisation context is required to subscribe.');
        }

        $db = db_connect('default');
        $userRow = $db->table('users')->where('id', $userId)->get()->getFirstRow('array');
        if (! $userRow) {
            return problem(404, 'user_not_found', 'The authenticated user account was not found.');
        }

        $user = [
            'id'    => $userRow['id'],
            'email' => $userRow['email'],
            'name'  => $userRow['name'] ?? 'Subscriber',
        ];

        // Pricing Matrix (LKR)
        $prices = [
            'monthly' => 15000.00,
            'annual'  => 150000.00,
            'single'  => 5000.00,
        ];

        $amount  = $prices[$plan] ?? 15000.00;
        $orderId = 'SUB-' . date('Ymd') . '-' . bin2hex(random_bytes(4));

        $db->table('orders')->insert([
            'order_id'   => $orderId,
            'org_id'     => $orgId,
            'user_id'    => $user['id'],
            'plan'       => $plan,
            'amount'     => $amount,
            'currency'   => 'LKR',
            'gateway'    => $gateway,
            'status'     => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $checkoutData = [
            'order_id'   => $orderId,
            'amount'     => $amount,
            'currency'   => 'LKR',
            'item_name'  => "TenderHub Business Plan ({$plan})",
            'first_name' => explode(' ', $user['name'])[0] ?? 'Subscriber',
            'last_name'  => explode(' ', $user['name'])[1] ?? 'User',
            'email'      => $user['email'],
            'phone'      => '0771234567',
        ];

        if ($gateway === 'stripe') {
            $session = PaymentGatewayService::createStripeSession($checkoutData);
        } else {
            $session = PaymentGatewayService::createPayHereCheckout($checkoutData);
        }

        return $this->ok([
            'order_id'   => $orderId,
            'plan'       => $plan,
            'amount'     => $amount,
            'session'    => $session,
        ]);
    }

    /**
     * POST /api/v1/payments/webhook/payhere
     * Asynchronous payment notification from PayHere gateway.
     */
    public function webhookPayHere()
    {
        $post = $this->body();

        if (! PaymentGatewayService::verifyPayHereWebhook($post)) {
            return problem(400, 'invalid_signature', 'Invalid PayHere webhook signature.');
        }

        $orderId    = (string) ($post['order_id'] ?? '');
        $statusCode = (int) ($post['status_code'] ?? 0);
        $paymentId  = (string) ($post['payment_id'] ?? '');

        $orderModel = model('App\Models\OrderModel');
        $order = $orderModel->where('order_id', $orderId)->first();

        if (! $order) {
            return problem(404, 'order_not_found', 'Order not found.');
        }

        // Verify amount matches order amount (2 decimal places)
        $orderAmt = number_format((float) $order['amount'], 2, '.', '');
        $postAmt  = number_format((float) ($post['payhere_amount'] ?? 0), 2, '.', '');
        if ($orderAmt !== $postAmt) {
            return problem(400, 'amount_mismatch', 'Payment amount does not match order amount.');
        }

        // Verify currency matches order currency
        $orderCurrency = strtoupper((string) ($order['currency'] ?? 'LKR'));
        $postCurrency  = strtoupper((string) ($post['payhere_currency'] ?? ''));
        if ($orderCurrency !== $postCurrency) {
            return problem(400, 'currency_mismatch', 'Payment currency does not match order currency.');
        }

        // Replay defense and State Machine verification
        if ($order['status'] === \App\Models\OrderModel::STATUS_PAID) {
            if ($statusCode === 2) {
                // Duplicate callback for already paid order: idempotent success response
                return $this->ok([
                    'status'     => 'paid',
                    'order_id'   => $order['order_id'],
                    'idempotent' => true,
                    'message'    => 'Payment already recorded.',
                ]);
            }

            // Conflicting callback trying to downgrade a paid order is strictly rejected
            return problem(409, 'cannot_downgrade_paid_order', 'Cannot downgrade completed payment.');
        }

        // Status 2 = Success in PayHere
        if ($statusCode === 2) {
            $db = db_connect('default');
            $db->transBegin();

            try {
                // Transition order state machine from pending -> paid
                $orderModel->transition($order['order_id'], \App\Models\OrderModel::STATUS_PAID, [
                    'transaction_id' => $paymentId,
                ]);

                // Advance organisation plan to business/active
                $months   = $order['plan'] === 'annual' ? 12 : 1;
                $renewsAt = date('Y-m-d H:i:s', strtotime("+{$months} months"));

                $db->table('organisations')->where('id', $order['org_id'])->update([
                    'plan'       => 'business',
                    'sub_status' => 'active',
                    'renews_at'  => $renewsAt,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                // Record into payments ledger
                $db->table('payments')->insert([
                    'org_id'     => $order['org_id'],
                    'user_id'    => $order['user_id'],
                    'plan'       => $order['plan'],
                    'term'       => $order['plan'] === 'annual' ? 'annual' : 'monthly',
                    'amount'     => $order['amount'],
                    'method'     => 'card',
                    'bank'       => 'PayHere',
                    'slip_ref'   => $paymentId,
                    'paid_on'    => date('Y-m-d'),
                    'channel'    => 'gateway',
                    'state'      => 'approved',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                $db->transCommit();

                service('eventLedger')->record('order', (int) $order['id'], 'order.paid', "Order {$order['order_id']} paid via PayHere (LKR {$order['amount']})", [
                    'order_id'       => $order['order_id'],
                    'transaction_id' => $paymentId,
                    'amount'         => $order['amount'],
                    'org_id'         => $order['org_id'],
                    'plan'           => $order['plan'],
                ]);
            } catch (\Throwable $e) {
                $db->transRollback();
                return problem(500, 'transition_failed', 'Could not complete order state transition.');
            }

            return $this->ok([
                'status'     => 'paid',
                'order_id'   => $order['order_id'],
                'idempotent' => false,
            ]);
        }

        // Status -1 (Canceled) or -2 (Failed)
        if ($statusCode === -1 || $statusCode === -2) {
            $orderModel->transition($order['order_id'], \App\Models\OrderModel::STATUS_FAILED, [
                'transaction_id' => $paymentId,
            ]);

            return $this->ok([
                'status'   => 'failed',
                'order_id' => $order['order_id'],
            ]);
        }

        return $this->ok([
            'status'   => $order['status'],
            'order_id' => $order['order_id'],
        ]);
    }

    /**
     * GET /api/v1/payments/orders/(:segment)
     * Retrieves an order ensuring caller owns the order and organisation.
     */
    public function show(string $orderId)
    {
        $userId = (int) ($this->request->userId ?? ($this->request->claims['sub'] ?? 0));
        $orgId  = (int) ($this->request->orgId ?? ($this->request->claims['org'] ?? 0));

        if ($userId <= 0) {
            return problem(401, 'unauthenticated', 'Authentication required.');
        }

        if ($orgId <= 0) {
            return problem(403, 'no_org_context', 'Organisation context required.');
        }

        $orderModel = model('App\Models\OrderModel');
        $order = $orderModel->where('order_id', $orderId)->first();

        if (! $order) {
            return problem(404, 'order_not_found', 'Order not found.');
        }

        // Strict cross-tenant and cross-user ownership isolation
        if ((int) $order['org_id'] !== $orgId || (int) $order['user_id'] !== $userId) {
            return problem(403, 'forbidden_order_access', 'You do not have permission to access this order.');
        }

        return $this->ok($order);
    }
}

