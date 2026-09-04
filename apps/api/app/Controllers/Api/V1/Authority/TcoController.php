<?php
namespace App\Controllers\Api\V1\Authority;
use CodeIgniter\HTTP\ResponseInterface;

/** Life-cycle cost / total cost of ownership assessment (NPC LCC guidance). */
class TcoController extends WorkspaceBase
{
    private const LINES = ['acquisition', 'installation', 'operating', 'maintenance', 'energy', 'replacement', 'disposal'];

    public function assess(int $id): ResponseInterface
    {
        if (! $this->procurement($id)) { return problem(404, 'not_found', 'No such tender.'); }
        $in = $this->body();
        $components = [];
        $total = 0.0;
        foreach (self::LINES as $line) {
            $v = (float) ($in[$line] ?? 0);
            $components[$line] = $v;
            $total += $v;
        }
        db_connect()->table('tco_assessments')->insert([
            'procurement_id' => $id, 'components' => json_encode($components), 'total' => $total,
            'created_by' => (int) $this->request->userId, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        service('eventLedger')->record('procurement', $id, 'tco.assessed', 'Life-cycle cost assessed', ['total' => $total]);
        return $this->ok(['components' => $components, 'lifecycle_cost' => $total,
            'acquisition_share' => $total > 0 ? round(($components['acquisition'] / $total) * 100, 1) : 0], [], 201);
    }

    public function show(int $id): ResponseInterface
    {
        if (! $this->procurement($id)) { return problem(404, 'not_found', 'No such tender.'); }
        $rows = db_connect()->table('tco_assessments')->where('procurement_id', $id)->orderBy('id', 'DESC')->get()->getResultArray();
        foreach ($rows as &$r) { $r['components'] = json_decode($r['components'], true); }
        return $this->ok($rows);
    }
}
