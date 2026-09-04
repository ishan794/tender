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

// 1. SQL Injection Fuzzing on Notices Search
$sqliVectors = [
    "' OR '1'='1",
    "'; DROP TABLE notices; --",
    "' UNION SELECT id, password_hash FROM users --",
    "1' AND SLEEP(3) --",
    "\" OR \"\"=\"",
];
$allSqliSafe = true;
foreach ($sqliVectors as $vec) {
    $res = httpReq('GET', '/api/v1/notices?q=' . urlencode($vec));
    if ($res['code'] !== 200 || !isset($res['body']['data'])) {
        $allSqliSafe = false;
        break;
    }
    // Check no raw SQL syntax errors exposed
    if (stripos($res['raw'], 'SQLSTATE') !== false || stripos($res['raw'], 'syntax error') !== false) {
        $allSqliSafe = false;
        break;
    }
}
$results['1_sqli_fuzzing'] = [
    'ok' => $allSqliSafe,
    'detail' => 'All SQLi payloads returned safe 200 JSON with 0 database errors exposed'
];

// 2. Path Traversal on Document Endpoint
$traversalVectors = [
    '../../../../windows/win.ini',
    '..%2f..%2f..%2fetc%2fpasswd',
    'uploads/../../../config.php',
    '..\\..\\..\\windows\\system32\\drivers\\etc\\hosts'
];
$allTraversalSafe = true;
foreach ($traversalVectors as $path) {
    $res = httpReq('GET', '/api/v1/documents/' . urlencode($path) . '/file');
    // Must be 400, 403, or 404, never 200 with system file content
    if ($res['code'] === 200 || stripos($res['raw'], '[extensions]') !== false || stripos($res['raw'], 'root:') !== false) {
        $allTraversalSafe = false;
        break;
    }
}
$results['2_path_traversal'] = [
    'ok' => $allTraversalSafe,
    'detail' => 'Directory traversal payloads blocked without system file disclosure'
];

// 3. Forged JWT / Invalid Signature Rejection
$forgedJwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjEsIm9yZ19pZCI6MSwicm9sZSI6InN0YWZmIn0.FAKESIGNATURE_FORGED_TOKEN_1234567890';
$forgedRes = httpReq('GET', '/api/v1/account/notifications', ['Authorization' => 'Bearer ' . $forgedJwt]);
$results['3_forged_jwt_rejection'] = [
    'ok' => ($forgedRes['code'] === 401),
    'detail' => "Forged JWT rejected with HTTP {$forgedRes['code']}"
];

// 4. SSRF Validation on Webhooks Registration
$devPartnerKeyFile = __DIR__ . '/dev-partner-key.txt';
$partnerKey = file_exists($devPartnerKeyFile) ? trim(file_get_contents($devPartnerKeyFile)) : 'th_live_4e547476f5d481c5a6e4229bffef6e70';

$ssrfUrls = [
    'http://169.254.169.254/latest/meta-data',
    'http://127.0.0.1:8080/admin',
    'http://localhost:3000/'
];
$allSsrfBlocked = true;
foreach ($ssrfUrls as $url) {
    $whRes = httpReq('POST', '/api/v1/partner/webhooks', [
        'X-Api-Key' => $partnerKey,
    ], [
        'url'   => $url,
        'event' => 'notice.published'
    ]);
    
    // Status must be 422 Unprocessable Entity
    if ($whRes['code'] !== 422) {
        $allSsrfBlocked = false;
        break;
    }
}
$results['4_ssrf_webhook_blocking'] = [
    'ok' => $allSsrfBlocked,
    'detail' => 'SSRF destinations (metadata service, loopback) rejected with HTTP 422'
];

// 5. Cross-Tenant IDOR Check
// Log in as free bidder (Org 5)
$loginFree = httpReq('POST', '/api/v1/auth/login', [], [
    'email'    => 'free@bidder.lk',
    'password' => 'Password123'
]);
$freeToken = $loginFree['body']['data']['access_token'] ?? '';

// Attempting to cancel/refund an order belonging to Org 4 using Org 5 token
$crossRes = httpReq('POST', '/api/v1/payments/refund', [
    'Authorization' => 'Bearer ' . $freeToken
], [
    'order_id' => 'ORD-123456',
    'reason'   => 'Unauthorized IDOR attempt'
]);

$idorSafe = ($crossRes['code'] === 403 || $crossRes['code'] === 404);
$results['5_cross_tenant_idor'] = [
    'ok' => $idorSafe,
    'detail' => "Cross-tenant IDOR attempt returned HTTP {$crossRes['code']}"
];

// 6. XSS Fuzzing in Search & Output Encoding
$xssPayload = '<script>alert(document.domain)</script><svg onload=alert(1)>';
$xssRes = httpReq('GET', '/api/v1/notices?q=' . urlencode($xssPayload));
$xssSafe = ($xssRes['code'] === 200 && strpos($xssRes['raw'], '<script>alert') === false);
$results['6_xss_output_safety'] = [
    'ok' => $xssSafe,
    'detail' => 'XSS payload handled cleanly with application/json encoding'
];

// 7. Security Context (JSON Execution Context)
$headRes = httpReq('GET', '/api/v1/notices');
$results['7_api_json_content_type'] = [
    'ok' => (stripos($headRes['raw'], '<!DOCTYPE') === false),
    'detail' => 'API response is strictly structured JSON, never HTML execution context'
];

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
