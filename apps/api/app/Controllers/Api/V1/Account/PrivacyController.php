<?php
namespace App\Controllers\Api\V1\Account;
use App\Controllers\Api\V1\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PDPA data-subject rights. Requests are logged for the controller to action;
 * "export" returns the requester's OWN personal data immediately. Deletion is
 * recorded as a request, NOT auto-executed — procurement records under a legal
 * hold or statutory retention must not be destroyed on request. Legal review of
 * what is actually deletable is a human decision, not an automated one.
 */
class PrivacyController extends BaseApiController
{
    public function request(): ResponseInterface
    {
        $kind = (string) ($this->body()['kind'] ?? '');
        if (! in_array($kind, ['access', 'export', 'correction', 'deletion'], true)) {
            return problem(422, 'validation_failed', 'kind must be access, export, correction or deletion.');
        }
        db_connect()->table('data_requests')->insert([
            'user_id' => (int) $this->request->userId, 'org_id' => (int) $this->request->orgId,
            'kind' => $kind, 'detail' => $this->body()['detail'] ?? null,
            'status' => 'received', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(['request' => $kind, 'status' => 'received',
            'note' => $kind === 'deletion' ? 'Deletion is reviewed against legal holds and retention obligations before any action.' : null], [], 201);
    }

    /** The requester's own personal data (PDPA right of access / portability). */
    public function export(): ResponseInterface
    {
        $uid = (int) $this->request->userId;
        $db  = db_connect();
        $user = $db->table('users')->select('id,name,email,phone,role,user_group,created_at,last_login_at')->where('id', $uid)->get()->getFirstRow('array');
        $org  = $db->table('organisations')->where('id', (int) $this->request->orgId)->get()->getFirstRow('array');
        return $this->ok([
            'user' => $user,
            'organisation' => $org ? ['id' => $org['id'], 'name' => $org['name'], 'type' => $org['type']] : null,
            'bids' => $db->table('bids')->where('owner_id', $uid)->get()->getResultArray(),
            'notifications' => $db->table('notifications')->where('user_id', $uid)->get()->getResultArray(),
            'generated_at' => date('c'),
        ]);
    }
}
