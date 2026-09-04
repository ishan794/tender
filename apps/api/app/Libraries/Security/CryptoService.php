<?php

namespace App\Libraries\Security;

use RuntimeException;

/**
 * Envelope encryption for sealed bids (AES-256-GCM).
 *
 * KEY HIERARCHY:
 *   master key ─wraps→ per-tender DEK ─encrypts→ each bid's sensitive fields
 *
 * The master key comes from env('ENCRYPTION_KEY') here. In production the master
 * key is custodied by a KMS (AWS KMS / Cloud KMS / Vault Transit): the DEK would
 * be wrapped/unwrapped by a KMS call rather than a local AES operation. That
 * swap is isolated to wrapDek()/unwrapDek() below, and is the single part marked
 *   BLOCKED — KMS INFRASTRUCTURE REQUIRED
 * Everything else — per-tender DEKs, GCM authenticated encryption, tamper
 * detection, plaintext never at rest — is complete and locally verifiable.
 */
final class CryptoService
{
    private function masterKey(): string
    {
        $k = (string) (env('ENCRYPTION_KEY') ?? env('files.signingKey') ?? '');
        if (strlen($k) < 32) {
            throw new RuntimeException('ENCRYPTION_KEY is missing or shorter than 32 characters.');
        }

        // Derive a fixed 32-byte key from whatever length the secret is.
        return substr(hash('sha256', $k, true), 0, 32);
    }

    private function gcmEncrypt(string $key, string $plaintext): array
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return ['ct' => base64_encode($ct), 'iv' => base64_encode($iv), 'tag' => base64_encode($tag)];
    }

    private function gcmDecrypt(string $key, string $ctB64, string $ivB64, string $tagB64): ?string
    {
        $pt = openssl_decrypt(
            base64_decode($ctB64), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            base64_decode($ivB64), base64_decode($tagB64),
        );

        return $pt === false ? null : $pt;   // false == authentication (tag) failure
    }

    // --- KMS boundary: swap these two for KMS wrap/unwrap in production -------
    private function wrapDek(string $dek): array
    {
        return $this->gcmEncrypt($this->masterKey(), $dek);
    }

    private function unwrapDek(string $ctB64, string $ivB64, string $tagB64): string
    {
        $dek = $this->gcmDecrypt($this->masterKey(), $ctB64, $ivB64, $tagB64);
        if ($dek === null) {
            throw new RuntimeException('DEK unwrap failed — wrong master key or tampered key material.');
        }

        return $dek;
    }
    // -------------------------------------------------------------------------

    /** Load or create the wrapped per-tender DEK; returns the plaintext DEK. */
    private function tenderKey(int $procId): string
    {
        $db  = db_connect();
        $row = $db->table('tender_keys')->where('procurement_id', $procId)->get()->getFirstRow('array');
        if ($row) {
            return $this->unwrapDek($row['wrapped_dek'], $row['iv'], $row['tag']);
        }

        $dek = random_bytes(32);
        $w   = $this->wrapDek($dek);
        $db->table('tender_keys')->insert([
            'procurement_id' => $procId, 'wrapped_dek' => $w['ct'], 'iv' => $w['iv'], 'tag' => $w['tag'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $dek;
    }

    /** Encrypt a bid's sensitive fields under the tender DEK. */
    public function seal(int $procId, int $submissionId, array $data): void
    {
        $e = $this->gcmEncrypt($this->tenderKey($procId), json_encode($data, JSON_UNESCAPED_UNICODE));
        db_connect()->table('bid_seals')->insert([
            'procurement_id' => $procId, 'submission_id' => $submissionId,
            'ciphertext' => $e['ct'], 'iv' => $e['iv'], 'tag' => $e['tag'], 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Decrypt every sealed bid for a tender. Called only after dual-control opening. */
    public function unsealAll(int $procId): array
    {
        $dek = $this->tenderKey($procId);
        $out = [];
        foreach (db_connect()->table('bid_seals')->where('procurement_id', $procId)->get()->getResultArray() as $s) {
            $pt = $this->gcmDecrypt($dek, $s['ciphertext'], $s['iv'], $s['tag']);
            $out[(int) $s['submission_id']] = $pt === null ? null : json_decode($pt, true);
        }

        return $out;
    }
}
