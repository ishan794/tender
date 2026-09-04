<?php

namespace App\Controllers\Api\V1\Authority;

use App\Controllers\Api\V1\BaseApiController;
use App\Models\ProcurementPlanModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Annual procurement planning for the procuring organisation.
 *
 *   draft → submitted → approved
 *   approved → (revise) → new draft revision, old row marked 'revised'
 *   approved → (link) → linked to the real tender it became
 *
 * Every state change is org-scoped and written to the event ledger. Approval
 * observes separation of duties: above the organisation's threshold, the person
 * who created a plan line cannot approve it.
 */
class PlanController extends BaseApiController
{
    private const METHODS = ['open', 'limited', 'rfq', 'shopping', 'direct', 'two_stage', 'framework'];

    private function orgId(): int
    {
        return (int) $this->request->orgId;
    }

    /** List the org's plan for a year, with a summary and plan-vs-actual. */
    public function index(): ResponseInterface
    {
        $year = (int) ($this->request->getGet('year') ?: date('Y'));
        $m    = model(ProcurementPlanModel::class);

        $rows = $m->where('org_id', $this->orgId())->where('year', $year)
            ->where('status !=', 'revised')
            ->orderBy('id', 'DESC')->findAll();

        // Plan vs actual: join each plan's linked procurement to its real stage.
        $db = db_connect();
        $summary = [
            'year' => $year, 'total_planned' => 0.0,
            'by_status' => ['draft' => 0, 'submitted' => 0, 'approved' => 0],
            'published_value' => 0.0, 'awarded_value' => 0.0, 'delayed' => 0,
        ];
        foreach ($rows as &$r) {
            $summary['total_planned'] += (float) $r['estimated_value'];
            $summary['by_status'][$r['status']] = ($summary['by_status'][$r['status']] ?? 0) + 1;

            $r['linked_stage'] = null;
            if ($r['linked_procurement_id']) {
                $proc = $db->table('procurements')->select('stage_idx')
                    ->where('id', $r['linked_procurement_id'])->get()->getFirstRow('array');
                $r['linked_stage'] = $proc ? (int) $proc['stage_idx'] : null;
                if ($r['linked_stage'] >= 2) {
                    $summary['published_value'] += (float) $r['estimated_value'];
                }
                if ($r['linked_stage'] >= 6) {
                    $summary['awarded_value'] += (float) $r['estimated_value'];
                }
            }
            // "Delayed": approved & past its planned tender date & not yet linked/published.
            if ($r['status'] === 'approved' && $r['planned_tender_date']
                && strtotime($r['planned_tender_date']) < time()
                && ($r['linked_stage'] === null || $r['linked_stage'] < 2)) {
                $summary['delayed']++;
            }
        }
        unset($r);

        return $this->ok($rows, ['summary' => $summary]);
    }

    public function create(): ResponseInterface
    {
        $in    = $this->body();
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            return problem(422, 'validation_failed', 'A plan line needs a title.');
        }
        $method = (string) ($in['procurement_method'] ?? 'open');
        if (! in_array($method, self::METHODS, true)) {
            return problem(422, 'validation_failed', 'Unknown procurement method.', ['allowed' => self::METHODS]);
        }

        $claims = (array) $this->request->claims;
        $m      = model(ProcurementPlanModel::class);
        $id     = $m->insert([
            'org_id'              => $this->orgId(),
            'year'               => (int) ($in['year'] ?? date('Y')),
            'title'              => $title,
            'department'         => $in['department'] ?? null,
            'project'            => $in['project'] ?? null,
            'category_id'        => isset($in['category_id']) ? (int) $in['category_id'] : null,
            'estimated_value'    => (float) ($in['estimated_value'] ?? 0),
            'funding_source'     => $in['funding_source'] ?? null,
            'procurement_method' => $method,
            'planned_tender_date'=> $in['planned_tender_date'] ?? null,
            'planned_award_date' => $in['planned_award_date'] ?? null,
            'budget_allocation'  => isset($in['budget_allocation']) ? (float) $in['budget_allocation'] : null,
            'officer_id'         => (int) $this->request->userId,
            'officer_name'       => $claims['nm'] ?? null,
            'status'             => 'draft',
            'created_by'         => (int) $this->request->userId,
        ], true);

        service('eventLedger')->record('plan', (int) $id, 'plan.created', "Plan line created: {$title}", [
            'year' => (int) ($in['year'] ?? date('Y')), 'value' => (float) ($in['estimated_value'] ?? 0),
        ]);

        return $this->ok($m->find($id), [], 201);
    }

    private function mine(int $id): ?array
    {
        return model(ProcurementPlanModel::class)->where('id', $id)->where('org_id', $this->orgId())->first();
    }

    public function submit(int $id): ResponseInterface
    {
        $p = $this->mine($id);
        if (! $p) {
            return problem(404, 'not_found', 'No such plan line.');
        }
        if ($p['status'] !== 'draft') {
            return problem(409, 'wrong_state', 'Only a draft plan can be submitted.');
        }
        model(ProcurementPlanModel::class)->update($id, ['status' => 'submitted']);
        service('eventLedger')->record('plan', $id, 'plan.submitted', 'Submitted for approval');

        return $this->ok(model(ProcurementPlanModel::class)->find($id));
    }

    public function approve(int $id): ResponseInterface
    {
        $p = $this->mine($id);
        if (! $p) {
            return problem(404, 'not_found', 'No such plan line.');
        }
        if ($p['status'] !== 'submitted') {
            return problem(409, 'wrong_state', 'This plan is not awaiting approval.');
        }

        $org       = model('App\Models\OrganisationModel')->find($this->orgId());
        $threshold = (float) $org['approval_threshold'];
        $me        = (int) $this->request->userId;
        if ((float) $p['estimated_value'] >= $threshold && (int) $p['created_by'] === $me) {
            return problem(403, 'self_approval', 'You cannot approve a plan line you created above the threshold.', [
                'threshold' => $threshold,
            ]);
        }

        model(ProcurementPlanModel::class)->update($id, [
            'status' => 'approved', 'approved_by' => $me, 'approved_at' => date('Y-m-d H:i:s'),
        ]);
        service('eventLedger')->record('plan', $id, 'plan.approved', 'Plan line approved');

        return $this->ok(model(ProcurementPlanModel::class)->find($id));
    }

    /** Create a new revision; the current row becomes 'revised' (history preserved). */
    public function revise(int $id): ResponseInterface
    {
        $p = $this->mine($id);
        if (! $p) {
            return problem(404, 'not_found', 'No such plan line.');
        }
        if ($p['status'] !== 'approved') {
            return problem(409, 'wrong_state', 'Only an approved plan can be revised.');
        }

        $in = $this->body();
        $m  = model(ProcurementPlanModel::class);
        $m->update($id, ['status' => 'revised']);

        $new = $p;
        unset($new['id'], $new['created_at'], $new['updated_at']);
        $new = array_merge($new, [
            'status'      => 'draft',
            'revision_of' => $id,
            'approved_by' => null,
            'approved_at' => null,
            'title'           => $in['title'] ?? $p['title'],
            'estimated_value' => isset($in['estimated_value']) ? (float) $in['estimated_value'] : $p['estimated_value'],
            'planned_tender_date' => $in['planned_tender_date'] ?? $p['planned_tender_date'],
        ]);
        $newId = $m->insert($new, true);

        service('eventLedger')->record('plan', (int) $newId, 'plan.revised', "Revision of plan #{$id}", ['revision_of' => $id]);

        return $this->ok($m->find($newId), [], 201);
    }

    public function linkTender(int $id): ResponseInterface
    {
        $p = $this->mine($id);
        if (! $p) {
            return problem(404, 'not_found', 'No such plan line.');
        }
        $procId = (int) ($this->body()['procurement_id'] ?? 0);
        // The tender must belong to the same org.
        $proc = db_connect()->table('procurements')->where('id', $procId)->where('org_id', $this->orgId())
            ->get()->getFirstRow('array');
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender in your organisation.');
        }
        model(ProcurementPlanModel::class)->update($id, ['linked_procurement_id' => $procId]);
        service('eventLedger')->record('plan', $id, 'plan.linked', "Linked to tender #{$procId}", ['procurement_id' => $procId]);

        return $this->ok(model(ProcurementPlanModel::class)->find($id));
    }
}
