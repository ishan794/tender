<?php
namespace App\Controllers\Api\V1\PublicApi;
use App\Controllers\Api\V1\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PUBLIC procurement transparency — aggregates only, from published notices and
 * post-standstill awards. Never exposes sealed bids, bidder identities before
 * opening, private documents or personal data. This is an open-data endpoint,
 * so it is deliberately outside the auth chain (read-only public aggregates).
 */
class TransparencyController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $db = db_connect();
        $one = fn (string $s) => (float) ($db->query($s)->getFirstRow('array')['v'] ?? 0);

        // Only awards whose standstill has passed are public.
        $awardsByDistrict = $db->query("SELECT d.name AS district, COUNT(a.id) AS awards, COALESCE(SUM(a.amount),0) AS value
            FROM awards a JOIN procurements p ON p.id=a.procurement_id JOIN notices n ON n.id=p.notice_id
            LEFT JOIN districts d ON d.id=n.district_id
            WHERE a.standstill_until < datetime('now') GROUP BY d.id ORDER BY value DESC")->getResultArray();

        return $this->ok([
            'published_notices' => $one("SELECT COUNT(*) v FROM notices WHERE status='published'"),
            'total_awarded_value' => $one("SELECT COALESCE(SUM(amount),0) v FROM awards WHERE standstill_until < datetime('now')"),
            'organisations' => $one("SELECT COUNT(*) v FROM organisations WHERE type='company'"),
            'suppliers' => $one("SELECT COUNT(*) v FROM organisations WHERE type='bidder'"),
            'open_notices' => $one("SELECT COUNT(*) v FROM notices WHERE status='published' AND closing_at > datetime('now')"),
            'closed_notices' => $one("SELECT COUNT(*) v FROM notices WHERE status='published' AND closing_at <= datetime('now')"),
            'awards_by_district' => $awardsByDistrict,
        ], ['source' => 'live_db', 'public' => true, 'server_now' => date('c')]);
    }
}
