<?php

namespace App\Libraries\Audit;

use CodeIgniter\Database\BaseConnection;

/**
 * Write and read the append-only Procurement Event Ledger.
 *
 * DESIGN INVARIANT: this class can INSERT and READ. It deliberately has no
 * update() and no delete(). "The ledger is append-only" is not a promise made
 * in a comment somewhere else — it is the absence of a mutation method here.
 *
 * Each row is chained to the previous row FOR THE SAME ENTITY:
 *   hash = sha256( prev_hash | entity_type | entity_id | event_type |
 *                  actor_id | actor_name | actor_role | org_id |
 *                  summary | payload_json | created_at )
 * so any edit or deletion of a historical row is detectable by verifyChain(),
 * which recomputes the chain and reports the first row that no longer agrees.
 */
final class EventLedger
{
    private BaseConnection $db;
    private const TABLE = 'event_ledger';

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * Append one event. Returns the stored row (including its hash).
     *
     * $actor defaults to the authenticated caller's JWT claims, captured at
     * write time so a later rename or deletion of the user cannot rewrite who
     * did what. Pass $actor explicitly for system-generated events.
     */
    public function record(
        string $entityType,
        int $entityId,
        string $eventType,
        ?string $summary = null,
        array $payload = [],
        ?array $actor = null,
    ): array {
        $actor ??= $this->actorFromRequest();
        $createdAt = date('Y-m-d H:i:s');

        $prevHash = (string) ($this->db->table(self::TABLE)
            ->select('hash')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()->getFirstRow('array')['hash'] ?? '');

        $payloadJson = $payload === [] ? null : $this->canonicalJson($payload);

        $row = [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'event_type'  => $eventType,
            'actor_id'    => $actor['id'] ?? null,
            'actor_name'  => $actor['name'] ?? null,
            'actor_role'  => $actor['role'] ?? null,
            'org_id'      => $actor['org'] ?? null,
            'summary'     => $summary,
            'payload'     => $payloadJson,
            'prev_hash'   => $prevHash !== '' ? $prevHash : null,
            'created_at'  => $createdAt,
        ];
        $row['hash'] = $this->hashOf($row, $prevHash);

        $this->db->table(self::TABLE)->insert($row);
        $row['id'] = (int) $this->db->insertID();

        return $row;
    }

    /** All events for one entity, oldest first. */
    public function forEntity(string $entityType, int $entityId): array
    {
        return $this->db->table(self::TABLE)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Recompute the per-entity hash chain and report integrity.
     * Returns ['ok' => bool, 'count' => int, 'broken_at' => ?int].
     */
    public function verifyChain(string $entityType, int $entityId): array
    {
        $rows = $this->forEntity($entityType, $entityId);
        $prev = '';
        foreach ($rows as $r) {
            $expected = $this->hashOf($r, $prev);
            if (! hash_equals($expected, (string) $r['hash'])) {
                return ['ok' => false, 'count' => count($rows), 'broken_at' => (int) $r['id']];
            }
            $prev = (string) $r['hash'];
        }

        return ['ok' => true, 'count' => count($rows), 'broken_at' => null];
    }

    // -------------------------------------------------------------------- //

    private function hashOf(array $row, string $prevHash): string
    {
        $canonical = implode('|', [
            $prevHash,
            $row['entity_type'],
            $row['entity_id'],
            $row['event_type'],
            $row['actor_id'] ?? '',
            $row['actor_name'] ?? '',
            $row['actor_role'] ?? '',
            $row['org_id'] ?? '',
            $row['summary'] ?? '',
            $row['payload'] ?? '',
            $row['created_at'],
        ]);

        return hash('sha256', $canonical);
    }

    private function canonicalJson(array $payload): string
    {
        ksort($payload);

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function actorFromRequest(): array
    {
        $req    = service('request');
        $claims = (array) ($req->claims ?? []);

        return [
            'id'   => isset($claims['sub']) ? (int) $claims['sub'] : null,
            'name' => $claims['nm'] ?? null,
            'role' => $claims['role'] ?? null,
            'org'  => isset($claims['org']) ? (int) $claims['org'] : null,
        ];
    }
}
