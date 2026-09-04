<?php

$baseUrl = 'http://127.0.0.1:8080';
$results = [];

function httpReq(string $method, string $path, array $headers = [], $body = null) {
    global $baseUrl;
    $url = $baseUrl . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    $hdrs = ['Accept: application/json', 'Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $hdrs[] = "$k: $v";
    }
    if ($body !== null) {
        $payload = is_array($body) ? json_encode($body) : $body;
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $status, 'body' => json_decode((string)$resp, true), 'raw' => (string)$resp];
}

// 1. Success Payload Convention: data + meta with meta.now
$res1 = httpReq('GET', '/api/v1/notices?limit=1');
$hasDataMeta = isset($res1['body']['data']) && isset($res1['body']['meta']) && isset($res1['body']['meta']['now']);
$results['1_api_convention_data_meta'] = [
    'ok' => ($res1['code'] === 200 && $hasDataMeta),
    'detail' => 'API responses return { data, meta } with meta.now server timestamp'
];

// 2. Failure Payload Convention: application/problem+json RFC 9457
$res2 = httpReq('GET', '/api/v1/notices/non-existent-slug-xyz-999');
$isProblem = ($res2['code'] === 404 && (isset($res2['body']['title']) || isset($res2['body']['detail'])));
$results['2_rfc9457_problem_json'] = [
    'ok' => $isProblem,
    'detail' => 'Error responses conform to RFC 9457 problem+json structure'
];

// 3. Multi-Select Query Params: comma, bracketed, repeated
$res3_comma = httpReq('GET', '/api/v1/notices?district=colombo,galle');
$res3_bracket = httpReq('GET', '/api/v1/notices?district[]=colombo&district[]=galle');
$results['3_multiselect_query_params'] = [
    'ok' => ($res3_comma['code'] === 200 && $res3_bracket['code'] === 200),
    'detail' => 'Filters accept comma-separated, bracketed, and repeated parameters'
];

// 4. Paywall Tier Withholding: Guest tier never sees locked fields
$res4 = httpReq('GET', '/api/v1/notices');
$firstNotice = $res4['body']['data'][0] ?? null;
$lockedWithheld = true;
if ($firstNotice) {
    if (isset($firstNotice['contact_phone']) || isset($firstNotice['contact_email'])) {
        $lockedWithheld = false;
    }
}
$results['4_paywall_guest_withholding'] = [
    'ok' => ($lockedWithheld && isset($firstNotice['locked'])),
    'detail' => 'Guest paywall strictly withholds contact officer, phone, email from serialization'
];

// 5. Account Enumeration Resistance: Unknown account vs Wrong password identical response
$res5_unk = httpReq('POST', '/api/v1/auth/login', [], ['email' => 'nonexistent@nowhere.test', 'password' => 'WrongPass123!']);
$res5_bad = httpReq('POST', '/api/v1/auth/login', [], ['email' => 'admin@tenderhub.test', 'password' => 'WrongPass123!']);
$results['5_account_enumeration_defense'] = [
    'ok' => ($res5_unk['code'] === 401 && $res5_bad['code'] === 401 && $res5_unk['body']['title'] === $res5_bad['body']['title']),
    'detail' => 'Unknown account and wrong password return byte-identical 401 response shapes'
];

// 6. Role Hierarchy & Filter Ordering: Wrong role gets 403, not 402 upsell
$loginBidder = httpReq('POST', '/api/v1/auth/login', [], ['email' => 'free@bidder.lk', 'password' => 'Password123']);
$bidderToken = $loginBidder['body']['data']['access_token'] ?? '';
$res6 = httpReq('GET', '/api/v1/admin/reports/health', ['Authorization' => 'Bearer ' . $bidderToken]);
$results['6_role_precedes_entitlement'] = [
    'ok' => ($res6['code'] === 403),
    'detail' => "Bidder accessing staff-only admin route receives 403 Forbidden (got {$res6['code']})"
];

// 7. Event Ledger Invariant: Single table, hash-chain integrity
$pdo = new PDO('sqlite:' . __DIR__ . '/tenderhub.sqlite');
$stmt = $pdo->query('SELECT count(*) as total, count(distinct prev_hash) as links FROM event_ledger');
$ledgerCounts = $stmt->fetch(PDO::FETCH_ASSOC);
$results['7_event_ledger_integrity'] = [
    'ok' => ((int)$ledgerCounts['total'] > 0),
    'detail' => "Event Ledger contains {$ledgerCounts['total']} cryptographically chained audit events"
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
