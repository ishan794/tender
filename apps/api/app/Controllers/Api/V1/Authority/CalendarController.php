<?php
namespace App\Controllers\Api\V1\Authority;
use App\Controllers\Api\V1\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/** Deadline intelligence for the procuring org: what needs attention and when. */
class CalendarController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $org = (int) $this->request->orgId;
        $db  = db_connect();
        $now = time();
        $events = [];

        foreach ($db->query("SELECT n.reference, n.title, n.closing_at, n.opening_at, p.id AS pid, p.stage_idx
            FROM procurements p JOIN notices n ON n.id = p.notice_id WHERE p.org_id = ?", [$org])->getResultArray() as $r) {
            if ($r['closing_at'] && (int) $r['stage_idx'] < 3) {
                $events[] = ['type' => 'tender_closing', 'ref' => $r['reference'], 'title' => $r['title'], 'at' => $r['closing_at']];
            }
            if ($r['opening_at'] && (int) $r['stage_idx'] === 3) {
                $events[] = ['type' => 'opening_ceremony', 'ref' => $r['reference'], 'title' => $r['title'], 'at' => $r['opening_at']];
            }
            if ((int) $r['stage_idx'] === 4) {
                $events[] = ['type' => 'evaluation_pending', 'ref' => $r['reference'], 'title' => $r['title'], 'at' => null];
            }
        }
        foreach ($db->query("SELECT a.standstill_until, n.reference FROM awards a
            JOIN procurements p ON p.id=a.procurement_id JOIN notices n ON n.id=p.notice_id
            WHERE p.org_id=?", [$org])->getResultArray() as $r) {
            if ($r['standstill_until'] && strtotime($r['standstill_until']) > $now) {
                $events[] = ['type' => 'standstill_ending', 'ref' => $r['reference'], 'title' => null, 'at' => $r['standstill_until']];
            }
        }
        foreach ($db->query("SELECT contract_no, end_date FROM contracts WHERE org_id=? AND end_date IS NOT NULL AND status IN ('active','suspended')", [$org])->getResultArray() as $r) {
            $events[] = ['type' => 'contract_expiring', 'ref' => $r['contract_no'], 'title' => null, 'at' => $r['end_date'] . ' 00:00:00'];
        }

        $bucket = ['closing_today' => 0, 'within_3_days' => 0, 'within_7_days' => 0, 'future' => 0];
        foreach ($events as $e) {
            if (! $e['at']) { continue; }
            $days = (strtotime($e['at']) - $now) / 86400;
            if ($days < 0) { continue; }
            if ($days < 1) { $bucket['closing_today']++; }
            elseif ($days <= 3) { $bucket['within_3_days']++; }
            elseif ($days <= 7) { $bucket['within_7_days']++; }
            else { $bucket['future']++; }
        }
        usort($events, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

        return $this->ok($events, ['buckets' => $bucket, 'server_now' => date('c')]);
    }
}
