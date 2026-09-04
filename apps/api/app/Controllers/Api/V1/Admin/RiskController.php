<?php
namespace App\Controllers\Api\V1\Admin;
use App\Controllers\Api\V1\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Procurement RISK SIGNALS — review flags, never accusations. Each signal names
 * what was observed, the source, and a severity for a human to review.
 */
class RiskController extends BaseApiController
{
    public function signals(): ResponseInterface
    {
        $db = db_connect();
        $signals = [];

        foreach ($db->query("SELECT p.id, n.reference, COUNT(s.id) c
            FROM procurements p JOIN notices n ON n.id=p.notice_id
            JOIN submissions s ON s.procurement_id=p.id
            WHERE p.stage_idx>=4 GROUP BY p.id HAVING c=1")->getResultArray() as $r) {
            $signals[] = ['signal' => 'single_bidder', 'severity' => 'medium', 'ref' => $r['reference'],
                'reason' => 'Only one bid was received for an opened tender.', 'review_state' => 'open'];
        }
        foreach ($db->query("SELECT p.id, n.reference, COUNT(a.id) c
            FROM procurements p JOIN notices n ON n.id=p.notice_id
            JOIN addenda a ON a.procurement_id=p.id GROUP BY p.id HAVING c>=3")->getResultArray() as $r) {
            $signals[] = ['signal' => 'repeated_date_changes', 'severity' => 'medium', 'ref' => $r['reference'],
                'reason' => "The closing date/terms changed {$r['c']} times via addenda.", 'review_state' => 'open'];
        }
        foreach ($db->query("SELECT o.name, COUNT(a.id) c FROM awards a
            JOIN organisations o ON o.id=a.supplier_org_id GROUP BY a.supplier_org_id HAVING c>=3")->getResultArray() as $r) {
            $signals[] = ['signal' => 'supplier_concentration', 'severity' => 'low', 'ref' => $r['name'],
                'reason' => "One supplier holds {$r['c']} awards.", 'review_state' => 'open'];
        }

        return $this->ok($signals, ['count' => count($signals), 'note' => 'Review signals only — not determinations of wrongdoing.']);
    }
}
