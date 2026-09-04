<?php

namespace App\Libraries\Signing;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Application-level digital signing.
 *
 * sign() records who signed what, when, over a SHA-256 hash of the exact
 * payload, sealed with an HMAC under the server signing key. verify()
 * recomputes it, so a tampered signature record is detectable.
 *
 * WHAT THIS IS NOT: a legally recognised electronic signature. Sri Lanka's
 * Electronic Transactions Act recognises signatures from accredited
 * certification authorities; binding to such a CA (or a provider like DocuSign)
 * is an external integration. That boundary is intentionally left as:
 *   BLOCKED — EXTERNAL SIGNING/CA PROVIDER REQUIRED
 * The internal attestation below is complete and verifiable on its own.
 */
final class SigningService
{
    private BaseConnection $db;
    private const TABLE = 'signatures';

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    private function key(): string
    {
        $k = (string) (env('files.signingKey') ?? '');
        if (strlen($k) < 32) {
            throw new RuntimeException('files.signingKey is missing or too short for signing.');
        }

        return $k;
    }

    /** Canonical payload → SHA-256, then an HMAC binding signer + time to it. */
    public function sign(string $entityType, int $entityId, string $event, array $payload, array $actor): array
    {
        ksort($payload);
        $docHash  = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $signedAt = date('Y-m-d H:i:s');
        $bind     = implode('|', [$entityType, $entityId, $event, $actor['id'] ?? '', $actor['name'] ?? '', $docHash, $signedAt]);
        $sig      = hash_hmac('sha256', $bind, $this->key());

        $row = [
            'entity_type' => $entityType, 'entity_id' => $entityId, 'event' => $event,
            'signer_id' => $actor['id'] ?? null, 'signer_name' => $actor['name'] ?? null,
            'signer_role' => $actor['role'] ?? null, 'org_id' => $actor['org'] ?? null,
            'doc_hash' => $docHash, 'signature' => $sig, 'signed_at' => $signedAt,
        ];
        $this->db->table(self::TABLE)->insert($row);
        $row['id'] = (int) $this->db->insertID();

        return $row;
    }

    public function forEntity(string $entityType, int $entityId): array
    {
        $rows = $this->db->table(self::TABLE)
            ->where('entity_type', $entityType)->where('entity_id', $entityId)
            ->orderBy('id', 'ASC')->get()->getResultArray();

        foreach ($rows as &$r) {
            $bind = implode('|', [$r['entity_type'], $r['entity_id'], $r['event'], $r['signer_id'] ?? '', $r['signer_name'] ?? '', $r['doc_hash'], $r['signed_at']]);
            $r['verified'] = hash_equals(hash_hmac('sha256', $bind, $this->key()), (string) $r['signature']);
        }

        return $rows;
    }
}
