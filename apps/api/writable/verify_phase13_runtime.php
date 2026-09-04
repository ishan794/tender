<?php
/**
 * Phase 13 Runtime Verification Script
 * Payments, Subscriptions, PayHere Webhook, State Machine, Bank Transfers, and Refunds.
 */

$baseUrl = 'http://127.0.0.1:8080';
$merchantId = '1211149';
$merchantSecret = 'super_secure_production_secret_key_999';
putenv("PAYHERE_MERCHANT_ID={$merchantId}");
putenv("PAYHERE_MERCHANT_SECRET={$merchantSecret}");
$_ENV['PAYHERE_MERCHANT_ID'] = $merchantId;
$_ENV['PAYHERE_MERCHANT_SECRET'] = $merchantSecret;

function httpReq($method, $url, $token = null, $data = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($body, true), 'raw' => $body];
}

// 1. Authenticate paid bidder (Org 4, business plan)
$loginBidder = httpReq('POST', "{$baseUrl}/api/v1/auth/login", null, [
    'email'    => 'paid@bidder.lk',
    'password' => 'Password123',
]);
$bidderToken = $loginBidder['body']['data']['access_token'] ?? '';
$bidderOrgId = $loginBidder['body']['data']['org']['id'] ?? 4;

// 2. Authenticate free bidder (Org 5, free plan)
$loginFree = httpReq('POST', "{$baseUrl}/api/v1/auth/login", null, [
    'email'    => 'free@bidder.lk',
    'password' => 'Password123',
]);
$freeToken = $loginFree['body']['data']['access_token'] ?? '';

// 3. Authenticate staff administrator
$loginStaff = httpReq('POST', "{$baseUrl}/api/v1/auth/login", null, [
    'email'    => 'staff@tenderhub.lk',
    'password' => 'Password123',
]);
$staffToken = $loginStaff['body']['data']['access_token'] ?? '';

$checks = [];

// Check 1: Checkout Session Initiation
$checkoutRes = httpReq('POST', "{$baseUrl}/api/v1/payments/checkout", $bidderToken, [
    'plan'    => 'annual',
    'gateway' => 'payhere',
]);
$orderId = $checkoutRes['body']['data']['order_id'] ?? null;
$checks['1_checkout_initiation'] = (
    $checkoutRes['code'] === 200 &&
    !empty($orderId) &&
    (float)($checkoutRes['body']['data']['amount'] ?? 0) == 150000.00
);

// Check 2: Order Ownership & Cross-Tenant Protection
$showOwn = httpReq('GET', "{$baseUrl}/api/v1/payments/orders/{$orderId}", $bidderToken);
$showCross = httpReq('GET', "{$baseUrl}/api/v1/payments/orders/{$orderId}", $freeToken);
$checks['2_order_tenant_isolation'] = ($showOwn['code'] === 200 && $showCross['code'] === 403);

// Check 3: PayHere Webhook Execution (State: pending -> paid)
$amountFormatted = '150000.00';
$currency = 'LKR';
$statusCode = '2';
$paymentId = 'PAYHERE-' . bin2hex(random_bytes(4));
$md5Sig = strtoupper(md5(
    $merchantId .
    $orderId .
    $amountFormatted .
    $currency .
    $statusCode .
    strtoupper(md5($merchantSecret))
));

$webhookRes = httpReq('POST', "{$baseUrl}/api/v1/payments/webhook/payhere", null, [
    'merchant_id'      => $merchantId,
    'order_id'         => $orderId,
    'payment_id'       => $paymentId,
    'payhere_amount'   => $amountFormatted,
    'payhere_currency' => $currency,
    'status_code'      => $statusCode,
    'md5sig'           => $md5Sig,
]);
$checks['3_webhook_payment_success'] = ($webhookRes['code'] === 200 && ($webhookRes['body']['data']['status'] ?? '') === 'paid');

// Check 4: Webhook Idempotency & Downgrade Prevention
$dupWebhook = httpReq('POST', "{$baseUrl}/api/v1/payments/webhook/payhere", null, [
    'merchant_id'      => $merchantId,
    'order_id'         => $orderId,
    'payment_id'       => $paymentId,
    'payhere_amount'   => $amountFormatted,
    'payhere_currency' => $currency,
    'status_code'      => $statusCode,
    'md5sig'           => $md5Sig,
]);
$idempotentOk = ($dupWebhook['code'] === 200 && ($dupWebhook['body']['data']['idempotent'] ?? false) === true);

// Attempt downgrade to failed (-1)
$failSig = strtoupper(md5(
    $merchantId . $orderId . $amountFormatted . $currency . '-1' . strtoupper(md5($merchantSecret))
));
$downgradeRes = httpReq('POST', "{$baseUrl}/api/v1/payments/webhook/payhere", null, [
    'merchant_id'      => $merchantId,
    'order_id'         => $orderId,
    'payment_id'       => $paymentId,
    'payhere_amount'   => $amountFormatted,
    'payhere_currency' => $currency,
    'status_code'      => '-1',
    'md5sig'           => $failSig,
]);
$downgradeBlocked = ($downgradeRes['code'] === 409);
$checks['4_idempotency_and_downgrade_defense'] = ($idempotentOk && $downgradeBlocked);

// Check 5: Bank Transfer Claim & Admin Confirmation / Rejection
// Create a payment claim via DB or API
$pdo = new PDO('sqlite:E:/tender/apps/api/writable/tenderhub.sqlite');
$pdo->prepare("INSERT INTO payments (org_id, user_id, plan, term, amount, method, bank, slip_ref, paid_on, channel, state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute([$bidderOrgId, 10, 'business', 'quarterly', 45000.00, 'bank_transfer', 'BOC', 'SLIP-99911', date('Y-m-d'), 'email', 'claimed', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
$claim1Id = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO payments (org_id, user_id, plan, term, amount, method, bank, slip_ref, paid_on, channel, state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute([$bidderOrgId, 10, 'business', 'annual', 150000.00, 'bank_transfer', 'HNB', 'SLIP-88822', date('Y-m-d'), 'email', 'claimed', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
$claim2Id = (int)$pdo->lastInsertId();

$confirmRes = httpReq('POST', "{$baseUrl}/api/v1/admin/payments/{$claim1Id}/confirm", $staffToken);
$rejectRes  = httpReq('POST', "{$baseUrl}/api/v1/admin/payments/{$claim2Id}/reject", $staffToken, [
    'reason' => 'Bank slip reference unverified by accounting.',
]);
$checks['5_bank_transfer_review'] = ($confirmRes['code'] === 200 && $rejectRes['code'] === 200);

$refundRes = httpReq('POST', "{$baseUrl}/api/v1/payments/refund", $staffToken, [
    'order_id' => $orderId,
    'reason'   => 'Customer requested cancellation within 24 hours.',
]);
$reRefundRes = httpReq('POST', "{$baseUrl}/api/v1/payments/refund", $staffToken, [
    'order_id' => $orderId,
    'reason'   => 'Duplicate refund request',
]);
$checks['6_refund_processing'] = ($refundRes['code'] === 200 && $reRefundRes['code'] === 400);

// Check 7: EventLedger Audit Records
$stmtEvents = $pdo->prepare("SELECT event_type FROM event_ledger WHERE entity_type IN ('order', 'payment') ORDER BY id DESC LIMIT 10");
$stmtEvents->execute();
$recentEvents = array_column($stmtEvents->fetchAll(PDO::FETCH_ASSOC), 'event_type');
$hasOrderPaid      = in_array('order.paid', $recentEvents, true);
$hasOrderRefunded  = in_array('order.refunded', $recentEvents, true);
$hasPaymentConfirm = in_array('payment.confirmed', $recentEvents, true);
$hasPaymentReject  = in_array('payment.rejected', $recentEvents, true);

$checks['7_event_ledger_audit'] = ($hasOrderPaid && $hasOrderRefunded && $hasPaymentConfirm && $hasPaymentReject);

echo "--- PHASE 13 RUNTIME VERIFICATION RESULTS ---\n";
print_r($checks);
$allPass = !in_array(false, $checks, true);
echo "OVERALL RESULT: " . ($allPass ? "ALL CHECKS PASSED (7/7)" : "FAILURES DETECTED") . "\n";
exit($allPass ? 0 : 1);
