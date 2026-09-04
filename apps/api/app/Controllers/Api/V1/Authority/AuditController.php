<?php

namespace App\Controllers\Api\V1\Authority;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Read the append-only Procurement Event Ledger for a tender.
 *
 * Read-only by construction — there is no write endpoint here. Events are
 * appended by the lifecycle controllers (submit, approve, publish, open,
 * award, addendum) as those actions happen, so the ledger cannot disagree with
 * what the system actually did. `meta.integrity` re-verifies the hash chain on
 * every read, so a tampered history is visible rather than silent.
 */
class AuditController extends WorkspaceBase
{
    public function ledger(int $id): ResponseInterface
    {
        // Org-scoped: only the owning organisation can read its own ledger.
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        $ledger = service('eventLedger');
        $events = $ledger->forEntity('procurement', $id);

        foreach ($events as &$e) {
            $e['id']       = (int) $e['id'];
            $e['actor_id'] = $e['actor_id'] !== null ? (int) $e['actor_id'] : null;
            $e['payload']  = $e['payload'] !== null ? json_decode((string) $e['payload'], true) : null;
        }
        unset($e);

        return $this->ok($events, [
            'tender'    => ['id' => (int) $proc['id'], 'reference' => $proc['reference']],
            'integrity' => $ledger->verifyChain('procurement', $id),
        ]);
    }
}
