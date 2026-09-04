<?php
namespace App\Controllers\Api\V1\Account;
use App\Controllers\Api\V1\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/** In-app notification centre for the signed-in user. */
class NotificationController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $uid = (int) $this->request->userId;
        $db  = db_connect();
        $rows = $db->table('notifications')->where('user_id', $uid)->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();
        $unread = (int) $db->table('notifications')->where('user_id', $uid)->where('read_at', null)->countAllResults();
        return $this->ok($rows, ['unread' => $unread]);
    }

    public function read(int $id): ResponseInterface
    {
        $uid = (int) $this->request->userId;
        $db  = db_connect();
        $n = $db->table('notifications')->where('id', $id)->where('user_id', $uid)->get()->getFirstRow('array');
        if (! $n) { return problem(404, 'not_found', 'No such notification.'); }
        $db->table('notifications')->where('id', $id)->update(['read_at' => date('Y-m-d H:i:s')]);
        return $this->ok(['id' => $id, 'read' => true]);
    }

    public function readAll(): ResponseInterface
    {
        $uid = (int) $this->request->userId;
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('notifications')
            ->where('user_id', $uid)
            ->where('read_at', null)
            ->update(['read_at' => $now]);

        return $this->ok(['success' => true, 'marked_at' => $now]);
    }

    public function unreadCount(): ResponseInterface
    {
        $uid = (int) $this->request->userId;
        $db  = db_connect();
        $unread = (int) $db->table('notifications')
            ->where('user_id', $uid)
            ->where('read_at', null)
            ->countAllResults();

        return $this->ok(['unread' => $unread]);
    }
}
