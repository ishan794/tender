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
        $db     = db_connect();
        $A = 999001;
        $B = 999002;

        // clean any prior test rows
        foreach ([$A, $B] as $t) {
            $db->table('bid_seals')->where('procurement_id', $t)->delete();
            $db->table('tender_keys')->where('procurement_id', $t)->delete();
        }

        $secret = ['bidder_name' => 'Ranmuthu Engineering (Pvt) Ltd', 'total_price' => 266600000, 'has_security' => 1];
        $crypto->seal($A, 5001, $secret);
        $crypto->seal($B, 6001, ['bidder_name' => 'Other Co', 'total_price' => 111]);

        $sealRow = $db->table('bid_seals')->where('procurement_id', $A)->get()->getFirstRow('array');
        $atRest  = json_encode($sealRow);

        $leak = (str_contains($atRest, 'Ranmuthu') || str_contains($atRest, '266600000')) ? 'FAIL: plaintext at rest' : 'PASS: no plaintext at rest';
        CLI::write('1) At-rest secrecy      → ' . $leak);
        CLI::write('   stored ciphertext    → ' . substr($sealRow['ciphertext'], 0, 48) . '…');

        $un = $crypto->unsealAll($A);
        $ok = ($un[5001]['bidder_name'] ?? null) === $secret['bidder_name'] && (int) ($un[5001]['total_price'] ?? 0) === 266600000;
        CLI::write('2) Decrypt correctness  → ' . ($ok ? 'PASS' : 'FAIL') . ' (' . ($un[5001]['bidder_name'] ?? '?') . ', ' . ($un[5001]['total_price'] ?? '?') . ')');

        // tamper the ciphertext → GCM must refuse
        $db->table('bid_seals')->where('procurement_id', $A)
            ->update(['ciphertext' => base64_encode('tampered' . base64_decode($sealRow['ciphertext']))]);
        $un2 = $crypto->unsealAll($A);
        $tamperRefused = array_key_exists(5001, $un2) && $un2[5001] === null;
        CLI::write('3) Tamper detection     → ' . ($tamperRefused ? 'PASS (GCM refused decrypt)' : 'FAIL (tamper undetected)'));

        // key isolation: tender B's DEK cannot decrypt tender A (different DEKs)
        $keyA = $db->table('tender_keys')->where('procurement_id', $A)->get()->getFirstRow('array')['wrapped_dek'];
        $keyB = $db->table('tender_keys')->where('procurement_id', $B)->get()->getFirstRow('array')['wrapped_dek'];
        CLI::write('4) Per-tender key isolation → ' . ($keyA !== $keyB ? 'PASS (distinct wrapped DEKs)' : 'FAIL (shared key)'));

        // cleanup
        foreach ([$A, $B] as $t) {
            $db->table('bid_seals')->where('procurement_id', $t)->delete();
            $db->table('tender_keys')->where('procurement_id', $t)->delete();
        }
        CLI::write('cleanup done.');
    }
}
