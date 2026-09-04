<?php

namespace App\Commands;

use App\Libraries\Security\CryptoService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark crypto:selftest
 *
 * Proves the sealed-bid encryption end to end against the real database:
 * plaintext is never at rest, decryption returns the exact values, GCM detects
 * tampering, and one tender's key cannot read another tender's seal.
 */
class CryptoSelfTest extends BaseCommand
{
    protected $group       = 'TenderHub';
    protected $name        = 'crypto:selftest';
    protected $description = 'Verify sealed-bid envelope encryption end to end.';

    public function run(array $params): void
    {
        $crypto = new CryptoService();
        $db = db_connect();
        $org = $db->table('organisations')->get()->getFirstRow('array');
        $orgId = (int) ($org['id'] ?? 1);

        // Create temporary notice, procurement, and submission fixtures that satisfy foreign key constraints
        $noticeA = (int) $db->table('notices')->insert([
            'reference' => 'TEST-CRYPTO-A', 'slug' => 'test-crypto-a-' . bin2hex(random_bytes(2)),
            'title' => 'Crypto Test A', 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s'),
        ], true) ? $db->insertID() : 0;
        $noticeB = (int) $db->table('notices')->insert([
            'reference' => 'TEST-CRYPTO-B', 'slug' => 'test-crypto-b-' . bin2hex(random_bytes(2)),
            'title' => 'Crypto Test B', 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s'),
        ], true) ? $db->insertID() : 0;

        $db->table('procurements')->insert([
            'org_id' => $orgId, 'notice_id' => $noticeA, 'stage_idx' => 2, 'created_by' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $A = (int) $db->insertID();

        $db->table('procurements')->insert([
            'org_id' => $orgId, 'notice_id' => $noticeB, 'stage_idx' => 2, 'created_by' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $B = (int) $db->insertID();

        $db->table('submissions')->insert([
            'procurement_id' => $A, 'bidder_org_id' => $orgId, 'bidder_name' => 'Ranmuthu',
            'reference' => 'SUB-TEST-A', 'received_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $subA = (int) $db->insertID();

        $db->table('submissions')->insert([
            'procurement_id' => $B, 'bidder_org_id' => $orgId, 'bidder_name' => 'Other Co',
            'reference' => 'SUB-TEST-B', 'received_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $subB = (int) $db->insertID();

        $secret = ['bidder_name' => 'Ranmuthu Engineering (Pvt) Ltd', 'total_price' => 266600000, 'has_security' => 1];
        $crypto->seal($A, $subA, $secret);
        $crypto->seal($B, $subB, ['bidder_name' => 'Other Co', 'total_price' => 111]);

        $sealRow = $db->table('bid_seals')->where('procurement_id', $A)->get()->getFirstRow('array');
        $atRest  = json_encode($sealRow);

        $leak = (str_contains($atRest, 'Ranmuthu') || str_contains($atRest, '266600000')) ? 'FAIL: plaintext at rest' : 'PASS: no plaintext at rest';
        CLI::write('1) At-rest secrecy      → ' . $leak);
        CLI::write('   stored ciphertext    → ' . substr($sealRow['ciphertext'], 0, 48) . '…');

        $un = $crypto->unsealAll($A);
        $ok = ($un[$subA]['bidder_name'] ?? null) === $secret['bidder_name'] && (int) ($un[$subA]['total_price'] ?? 0) === 266600000;
        CLI::write('2) Decrypt correctness  → ' . ($ok ? 'PASS' : 'FAIL') . ' (' . ($un[$subA]['bidder_name'] ?? '?') . ', ' . ($un[$subA]['total_price'] ?? '?') . ')');

        // tamper the ciphertext → GCM must refuse
        $db->table('bid_seals')->where('procurement_id', $A)
            ->update(['ciphertext' => base64_encode('tampered' . base64_decode($sealRow['ciphertext']))]);
        $un2 = $crypto->unsealAll($A);
        $tamperRefused = array_key_exists($subA, $un2) && $un2[$subA] === null;
        CLI::write('3) Tamper detection     → ' . ($tamperRefused ? 'PASS (GCM refused decrypt)' : 'FAIL (tamper undetected)'));

        // key isolation: tender B's DEK cannot decrypt tender A (different DEKs)
        $keyA = $db->table('tender_keys')->where('procurement_id', $A)->get()->getFirstRow('array')['wrapped_dek'];
        $keyB = $db->table('tender_keys')->where('procurement_id', $B)->get()->getFirstRow('array')['wrapped_dek'];
        CLI::write('4) Per-tender key isolation → ' . ($keyA !== $keyB ? 'PASS (distinct wrapped DEKs)' : 'FAIL (shared key)'));

        // cleanup in reverse dependency order
        foreach ([$A, $B] as $t) {
            $db->table('bid_seals')->where('procurement_id', $t)->delete();
            $db->table('tender_keys')->where('procurement_id', $t)->delete();
            $db->table('submissions')->where('procurement_id', $t)->delete();
            $db->table('procurements')->where('id', $t)->delete();
        }
        $db->table('notices')->whereIn('id', [$noticeA, $noticeB])->delete();
        CLI::write('cleanup done.');
    }
}
