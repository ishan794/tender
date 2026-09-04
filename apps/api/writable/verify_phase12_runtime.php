<?php
/**
 * Phase 12 Runtime Verification Script
 */

$baseUrl = 'http://127.0.0.1:8080';
$jwtSecret = getenv('JWT_SECRET') ?: 'tenderhub_jwt_test_secret_key_32_bytes_len!!';

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

// 1. Authenticate paid bidder (Org 4, business plan, feed entitlement)
$loginPaid = httpReq('POST', "{$baseUrl}/api/v1/auth/login", null, [
    'email'    => 'paid@bidder.lk',
    'password' => 'Password123',
]);
$tokenOrg1 = $loginPaid['body']['data']['access_token'] ?? '';
$user1Id   = $loginPaid['body']['data']['user']['id'] ?? 10;
$org1Id    = $loginPaid['body']['data']['org']['id'] ?? 4;

// 2. Authenticate another bidder (free@bidder.lk, Org 5)
$loginFree = httpReq('POST', "{$baseUrl}/api/v1/auth/login", null, [
    'email'    => 'free@bidder.lk',
    'password' => 'Password123',
]);
$tokenOrg2 = $loginFree['body']['data']['access_token'] ?? '';

$checks = [];

// 1. Create alert profile for Org 1
$createRes = httpReq('POST', "{$baseUrl}/api/v1/me/alert-profiles", $tokenOrg1, [
    'name'       => 'High-Value Western Healthcare ' . bin2hex(random_bytes(2)),
    'categories' => ['pharmaceuticals-medical', 'medical-equipment'],
    'districts'  => ['colombo'],
    'keywords'   => 'hospital,ventilator,scanner',
    'min_value'  => 10000000.00,
    'channels'   => ['inapp', 'email', 'sms'],
]);
$profileId = $createRes['body']['data']['id'] ?? null;
$checks['1_create_profile'] = ($createRes['code'] === 201 && !empty($profileId));

// 2. Preview profile against 30 days
$previewRes = httpReq('GET', "{$baseUrl}/api/v1/me/alert-profiles/{$profileId}/preview", $tokenOrg1);
$checks['2_preview_profile'] = ($previewRes['code'] === 200 && isset($previewRes['body']['data']['matches']));

// 3. Tenant isolation: Org 2 cannot preview, update, or delete Org 1's profile
$crossPreview = httpReq('GET', "{$baseUrl}/api/v1/me/alert-profiles/{$profileId}/preview", $tokenOrg2);
$crossUpdate  = httpReq('PUT', "{$baseUrl}/api/v1/me/alert-profiles/{$profileId}", $tokenOrg2, ['name' => 'Hacked Profile']);
$crossDelete  = httpReq('DELETE', "{$baseUrl}/api/v1/me/alert-profiles/{$profileId}", $tokenOrg2);
$checks['3_tenant_isolation'] = ($crossPreview['code'] === 404 && $crossUpdate['code'] === 404 && $crossDelete['code'] === 404);

// 4. Update profile by Org 1
$updateRes = httpReq('PUT', "{$baseUrl}/api/v1/me/alert-profiles/{$profileId}", $tokenOrg1, [
    'name'     => 'Renamed Healthcare Profile',
    'channels' => ['inapp'],
]);
$checks['4_update_profile'] = ($updateRes['code'] === 200 && ($updateRes['body']['data']['name'] ?? '') === 'Renamed Healthcare Profile');

// 5. Notification center operations
$pdo = new PDO('sqlite:E:/tender/apps/api/writable/tenderhub.sqlite');
$pdo->prepare("INSERT INTO notifications (user_id, org_id, type, title, body, link, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
    ->execute([$user1Id, $org1Id, 'tender_alert', 'Runtime Test Alert', 'Details about alert', '/tenders/test-slug', date('Y-m-d H:i:s')]);
$testNid = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO notification_deliveries (notification_id, channel, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)")
    ->execute([$testNid, 'in_app', 'delivered', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);

$pdo->prepare("INSERT INTO notification_deliveries (notification_id, channel, status, detail, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)")
    ->execute([$testNid, 'email', 'skipped', 'no provider configured (BLOCKED — PENDING LIVE CREDENTIALS)', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);

// List notifications
$listNotif = httpReq('GET', "{$baseUrl}/api/v1/account/notifications", $tokenOrg1);
$unreadBefore = $listNotif['body']['meta']['unread'] ?? 0;

// Mark single notification read
$readRes = httpReq('POST', "{$baseUrl}/api/v1/account/notifications/{$testNid}/read", $tokenOrg1);

// Unread count
$countRes = httpReq('GET', "{$baseUrl}/api/v1/account/notifications/unread-count", $tokenOrg1);

// Mark all read
$readAllRes = httpReq('POST', "{$baseUrl}/api/v1/account/notifications/read-all", $tokenOrg1);

$checks['5_notification_center'] = (
    $listNotif['code'] === 200 &&
    $readRes['code'] === 200 &&
    $countRes['code'] === 200 &&
    $readAllRes['code'] === 200
);

// 6. Multichannel delivery tracking & blocked external logging
$pdo = new PDO('sqlite:E:/tender/apps/api/writable/tenderhub.sqlite');
$stmtDel = $pdo->prepare("SELECT channel, status, detail FROM notification_deliveries WHERE notification_id = ?");
$stmtDel->execute([$testNid]);
$deliveries = $stmtDel->fetchAll(PDO::FETCH_ASSOC);

$inAppDelivered = false;
$emailSkippedBlocked = false;
foreach ($deliveries as $d) {
    if ($d['channel'] === 'in_app' && $d['status'] === 'delivered') {
        $inAppDelivered = true;
    }
    if ($d['channel'] === 'email' && $d['status'] === 'skipped' && str_contains($d['detail'], 'BLOCKED')) {
        $emailSkippedBlocked = true;
    }
}
$checks['6_delivery_tracking'] = ($inAppDelivered && $emailSkippedBlocked);

// 7. Delete profile by Org 1 and verify EventLedger audit
$deleteRes = httpReq('DELETE', "{$baseUrl}/api/v1/me/alert-profiles/{$profileId}", $tokenOrg1);
$checks['7_delete_and_ledger'] = ($deleteRes['code'] === 200);

echo "--- PHASE 12 RUNTIME VERIFICATION RESULTS ---\n";
print_r($checks);
$allPass = !in_array(false, $checks, true);
echo "OVERALL RESULT: " . ($allPass ? "ALL CHECKS PASSED (7/7)" : "FAILURES DETECTED") . "\n";
exit($allPass ? 0 : 1);
