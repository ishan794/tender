<?php

namespace App\Controllers\Api\V1\Authority;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Attach a digital signature to a material tender event (approval, publication,
 * addendum, opening, award). The signature is an app-level HMAC attestation
 * bound to the signer, the time and a hash of the tender's current state.
 */
class SigningController extends WorkspaceBase
{
    private const EVENTS = ['approval', 'publication', 'addendum', 'opening', 'award', 'contract'];

    public function sign(int $id): ResponseInterface
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }
        $event = (string) ($this->body()['event'] ?? '');
        if (! in_array($event, self::EVENTS, true)) {
            return problem(422, 'validation_failed', 'Unknown signing event.', ['allowed' => self::EVENTS]);
        }

        $claims = (array) $this->request->claims;
        $actor  = [
            'id' => (int) $this->request->userId, 'name' => $claims['nm'] ?? null,
            'role' => $claims['role'] ?? null, 'org' => (int) $this->request->orgId,
        ];
        // Sign a snapshot of the state that matters for this event.
        $payload = [
            'reference' => $proc['reference'], 'stage_idx' => (int) $proc['stage_idx'],
            'event' => $event, 'value' => (float) ($proc['estimated_value'] ?? 0),
        ];

        $sig = service('signing')->sign('procurement', $id, $event, $payload, $actor);
        service('eventLedger')->record('procurement', $id, 'signed.' . $event,
            ucfirst($event) . ' digitally signed by ' . ($actor['name'] ?? 'an officer'), ['doc_hash' => $sig['doc_hash']]);

        return $this->ok([
            'event' => $event, 'signer' => $sig['signer_name'], 'signed_at' => $sig['signed_at'],
            'doc_hash' => $sig['doc_hash'], 'verified' => true,
        ], [], 201);
    }

    public function forTender(int $id): ResponseInterface
    {
        if (! $this->procurement($id)) {
            return problem(404, 'not_found', 'No such tender.');
        }

        return $this->ok(service('signing')->forEntity('procurement', $id));
    }
}
