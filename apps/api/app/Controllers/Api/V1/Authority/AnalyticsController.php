<?php
namespace App\Controllers\Api\V1\Authority;
use App\Controllers\Api\V1\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/** Procurement analytics from REAL data, org-scoped. No hardcoded numbers. */
class AnalyticsController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $org = (int) $this->request->orgId;
        $db  = db_connect();
        $one = fn (string $sql) => (float) ($db->query($sql, [$org])->getFirstRow('array')['v'] ?? 0);

        $total     = $one("SELECT COUNT(*) v FROM procurements WHERE org_id=?");
        $published = $one("SELECT COUNT(*) v FROM procurements WHERE org_id=? AND stage_idx>=2");
        $awarded   = $one("SELECT COUNT(*) v FROM procurements WHERE org_id=? AND stage_idx>=6");
        $value     = $one("SELECT COALESCE(SUM(n.estimated_value),0) v FROM procurements p JOIN notices n ON n.id=p.notice_id WHERE p.org_id=?");
        $withBids  = $one("SELECT COUNT(DISTINCT procurement_id) v FROM submissions s JOIN procurements p ON p.id=s.procurement_id WHERE p.org_id=?");
        $bids      = $one("SELECT COUNT(*) v FROM submissions s JOIN procurements p ON p.id=s.procurement_id WHERE p.org_id=?");
        $amended   = $one("SELECT COUNT(DISTINCT procurement_id) v FROM addenda a JOIN procurements p ON p.id=a.procurement_id WHERE p.org_id=?");

        return $this->ok([
            'tenders_total' => $total,
            'tenders_published' => $published,
            'tenders_awarded' => $awarded,
            'total_estimated_value' => $value,
            'award_rate' => $published > 0 ? round($awarded / $published, 3) : 0,
            'avg_bidders_per_tender' => $withBids > 0 ? round($bids / $withBids, 2) : 0,
            'competition_rate' => $published > 0 ? round($withBids / $published, 3) : 0,
            'amendment_rate' => $published > 0 ? round($amended / $published, 3) : 0,
        ], ['source' => 'live_db', 'server_now' => date('c')]);
    }
}
