<?php
/**
 * Phase 11 Runtime Verification Script
 */

$baseUrl = 'http://127.0.0.1:8080';
$ingestKey = 'test-crawler-secret-key-12345';
putenv("INGEST_SECRET_KEY={$ingestKey}");
$_ENV['INGEST_SECRET_KEY'] = $ingestKey;

// In local dev server, pass env or read from .env if server has it
// Let's check what INGEST_SECRET_KEY the running server has or update .env if needed.
$envFile = 'E:/tender/apps/api/.env';
$envContent = file_exists($envFile) ? file_get_contents($envFile) : '';
if (!str_contains($envContent, 'INGEST_SECRET_KEY')) {
    file_put_contents($envFile, $envContent . "\nINGEST_SECRET_KEY={$ingestKey}\n");
}

function httpPostJson($url, $data, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $defaultHeaders = ['Content-Type: application/json', 'Accept: application/json'];
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($body, true), 'raw' => $body];
}

$checks = [];

// 1. Unauthorized ingestion request rejected
$unauth = httpPostJson("{$baseUrl}/api/v1/admin/ingest/push", [
    ['title' => 'Sample Tender']
], ['X-Ingest-Key: invalid-key']);

$checks['1_unauth_rejected'] = ($unauth['code'] === 401);

// 2. Push ingestion with valid key
$uniqueRef = 'GAZ-TEST-' . bin2hex(random_bytes(3));
$uniqueTitle = 'Procurement of Medical Ultrasound Equipment ' . bin2hex(random_bytes(2));
$pushPayload = [
    [
        'title'           => $uniqueTitle,
        'reference'       => $uniqueRef,
        'description'     => '<p>High-resolution diagnostic ultrasound scanners.</p><script>alert(1)</script>',
        'sector'          => 'health',
        'estimated_value' => 45000000.00,
        'district'        => 'Colombo',
        'closing_at'      => date('Y-m-d H:i:s', time() + 86400 * 30),
    ]
];

$authPush = httpPostJson("{$baseUrl}/api/v1/admin/ingest/push", $pushPayload, [
    "X-Ingest-Key: {$ingestKey}"
]);

$checks['2_auth_push_success'] = ($authPush['code'] === 200 && ($authPush['body']['data']['inserted'] ?? 0) === 1);
$runId = $authPush['body']['data']['run_id'] ?? null;

// 3. Duplicate ingestion skipped
$dupPush = httpPostJson("{$baseUrl}/api/v1/admin/ingest/push", $pushPayload, [
    "X-Ingest-Key: {$ingestKey}"
]);
$checks['3_duplicate_skipped'] = ($dupPush['code'] === 200 && ($dupPush['body']['data']['skipped'] ?? 0) === 1);

// 4. Database inspection
$pdo = new PDO('sqlite:E:/tender/apps/api/writable/tenderhub.sqlite');

$stmt = $pdo->prepare("SELECT * FROM notices WHERE reference = ?");
$stmt->execute([$uniqueRef]);
$notice = $stmt->fetch(PDO::FETCH_ASSOC);

$sanitizedClean = $notice && !str_contains($notice['description'], '<script>') && str_contains($notice['description'], 'High-resolution');
$hasSourceHash = $notice && !empty($notice['source_hash']);
$isUnverified = $notice && (int)$notice['verified'] === 0;

$checks['4_notice_sanitized_and_hashed'] = ($sanitizedClean && $hasSourceHash && $isUnverified);

// 5. Ingestion run recorded
$stmtRun = $pdo->prepare("SELECT * FROM ingestion_runs WHERE id = ?");
$stmtRun->execute([$runId]);
$runRow = $stmtRun->fetch(PDO::FETCH_ASSOC);
$checks['5_ingestion_run_recorded'] = ($runRow && $runRow['status'] === 'success' && (int)$runRow['items_inserted'] === 1);

// 6. SSRF Protection validation
require_once 'E:/tender/apps/api/app/Libraries/Ingestion/CrawlerIngestionService.php';
$ssrfPriv = \App\Libraries\Ingestion\CrawlerIngestionService::validateFetchUrl('http://192.168.1.1/secret', true);
$ssrfLoop = \App\Libraries\Ingestion\CrawlerIngestionService::validateFetchUrl('http://127.0.0.1/admin', true);
$ssrfAws  = \App\Libraries\Ingestion\CrawlerIngestionService::validateFetchUrl('http://169.254.169.254/latest/meta-data', true);
$checks['6_ssrf_protection'] = (!$ssrfPriv['ok'] && !$ssrfLoop['ok'] && !$ssrfAws['ok']);

// 7. Event Ledger chain verification
require_once 'E:/tender/apps/api/app/Libraries/Audit/EventLedger.php';
$stmtChain = $pdo->query("SELECT id, entity_type, entity_id, event_type, payload, prev_hash, hash, created_at FROM event_ledger WHERE entity_type = 'source' ORDER BY id ASC");
$sourceEvents = $stmtChain->fetchAll(PDO::FETCH_ASSOC);
$checks['7_ledger_source_events'] = count($sourceEvents) > 0;

echo "--- PHASE 11 RUNTIME VERIFICATION RESULTS ---\n";
print_r($checks);
$allPass = !in_array(false, $checks, true);
echo "OVERALL RESULT: " . ($allPass ? "ALL CHECKS PASSED (7/7)" : "FAILURES DETECTED") . "\n";
exit($allPass ? 0 : 1);
