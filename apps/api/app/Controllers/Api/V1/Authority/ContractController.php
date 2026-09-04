<?php

namespace App\Controllers\Api\V1\Authority;

use App\Models\ContractModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Contract management — the lifecycle after award. Org-scoped throughout; every
 * material change writes to the event ledger.
 *
 *   draft → active → (suspended) → completed → closed
 *   + milestones, variations (change value/end date), invoices, retention.
 */
class ContractController extends WorkspaceBase
{
    private function orgId(): int
    {
        return (int) $this->request->orgId;
    }

    private function mine(int $id): ?array
    {
        return model(ContractModel::class)->where('id', $id)->where('org_id', $this->orgId())->first();
    }

    public function index(): ResponseInterface
    {
        return $this->ok(model(ContractModel::class)->where('org_id', $this->orgId())->orderBy('id', 'DESC')->findAll());
    }

    /** Create a contract from an AWARDED tender in this org. */
    public function create(): ResponseInterface
    {
        $in     = $this->body();
        $procId = (int) ($in['procurement_id'] ?? 0);
        $proc   = $this->procurement($procId);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender in your organisation.');
        }
        if ((int) $proc['stage_idx'] < 6) {
            return problem(409, 'not_awarded', 'A contract can only be created for an awarded tender.');
        }
        if (model(ContractModel::class)->where('procurement_id', $procId)->countAllResults()) {
            return problem(409, 'exists', 'A contract already exists for this tender.');
        }

        $db    = db_connect();
        $award = $db->table('awards')->where('procurement_id', $procId)->get()->getFirstRow('array');
        $sup   = $award ? $db->table('organisations')->where('id', (int) $award['supplier_org_id'])->get()->getFirstRow('array') : null;

        $no = 'CON-' . date('Y') . '-' . str_pad((string) $procId, 4, '0', STR_PAD_LEFT);
        $m  = model(ContractModel::class);
        $id = $m->insert([
            'contract_no'          => $no,
            'procurement_id'       => $procId,
            'award_id'             => $award ? (int) $award['id'] : null,
            'org_id'               => $this->orgId(),
            'supplier_org_id'      => $award ? (int) $award['supplier_org_id'] : null,
            'supplier_name'        => $sup['name'] ?? ($in['supplier_name'] ?? null),
            'title'                => $in['title'] ?? ($proc['title'] ?? 'Contract'),
            'value'                => (float) ($in['value'] ?? ($award['amount'] ?? 0)),
            'start_date'           => $in['start_date'] ?? null,
            'end_date'             => $in['end_date'] ?? null,
            'performance_security' => isset($in['performance_security']) ? (float) $in['performance_security'] : null,
            'retention_pct'        => isset($in['retention_pct']) ? (float) $in['retention_pct'] : null,
            'status'               => 'draft',
            'created_by'           => (int) $this->request->userId,
        ], true);

        service('eventLedger')->record('contract', (int) $id, 'contract.created', "Contract {$no} created", [
            'procurement_id' => $procId, 'value' => (float) ($in['value'] ?? ($award['amount'] ?? 0)),
        ]);
        // Also surface it on the tender's own timeline.
        service('eventLedger')->record('procurement', $procId, 'contract.created', "Contract {$no} created");

        return $this->ok($m->find($id), [], 201);
    }

    public function show(int $id): ResponseInterface
    {
        $c = $this->mine($id);
        if (! $c) {
            return problem(404, 'not_found', 'No such contract.');
        }
        $db = db_connect();

        return $this->ok($c, [
            'milestones' => $db->table('contract_milestones')->where('contract_id', $id)->orderBy('id')->get()->getResultArray(),
            'variations' => $db->table('contract_variations')->where('contract_id', $id)->orderBy('id')->get()->getResultArray(),
            'invoices'   => $db->table('contract_invoices')->where('contract_id', $id)->orderBy('id')->get()->getResultArray(),
        ]);
    }

    public function activate(int $id): ResponseInterface
    {
        $c = $this->mine($id);
        if (! $c) {
            return problem(404, 'not_found', 'No such contract.');
        }
        if ($c['status'] !== 'draft') {
            return problem(409, 'wrong_state', 'Only a draft contract can be activated.');
        }
        if (! $c['start_date'] || ! $c['end_date']) {
            return problem(422, 'validation_failed', 'Start and end dates are required to activate a contract.');
        }
        model(ContractModel::class)->update($id, ['status' => 'active']);
        service('eventLedger')->record('contract', $id, 'contract.activated', 'Contract activated');

        return $this->ok(model(ContractModel::class)->find($id));
    }

    public function transition(int $id): ResponseInterface
    {
        $c = $this->mine($id);
        if (! $c) {
            return problem(404, 'not_found', 'No such contract.');
        }
        $to   = (string) ($this->body()['status'] ?? '');
        $allowed = [
            'suspended' => ['active'],
            'active'    => ['suspended'],
            'completed' => ['active'],
            'closed'    => ['completed'],
            'terminated'=> ['active', 'suspended'],
        ];
        if (! isset($allowed[$to]) || ! in_array($c['status'], $allowed[$to], true)) {
            return problem(409, 'wrong_state', "A '{$c['status']}' contract cannot become '{$to}'.");
        }
        model(ContractModel::class)->update($id, ['status' => $to]);
        service('eventLedger')->record('contract', $id, 'contract.' . $to, "Contract {$to}");

        return $this->ok(model(ContractModel::class)->find($id));
    }

    public function addMilestone(int $id): ResponseInterface
    {
        $c = $this->mine($id);
        if (! $c) {
            return problem(404, 'not_found', 'No such contract.');
        }
        $in = $this->body();
        if (trim((string) ($in['title'] ?? '')) === '') {
            return problem(422, 'validation_failed', 'A milestone needs a title.');
        }
        $db  = db_connect();
        $db->table('contract_milestones')->insert([
            'contract_id' => $id, 'title' => $in['title'],
            'due_date' => $in['due_date'] ?? null, 'amount' => isset($in['amount']) ? (float) $in['amount'] : null,
            'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        service('eventLedger')->record('contract', $id, 'contract.milestone_added', "Milestone: {$in['title']}");

        return $this->ok(['milestone_id' => $db->insertID()], [], 201);
    }

    public function meetMilestone(int $id, int $mid): ResponseInterface
    {
        if (! $this->mine($id)) {
            return problem(404, 'not_found', 'No such contract.');
        }
        $db = db_connect();
        $ms = $db->table('contract_milestones')->where('id', $mid)->where('contract_id', $id)->get()->getFirstRow('array');
        if (! $ms) {
            return problem(404, 'not_found', 'No such milestone.');
        }
        $db->table('contract_milestones')->where('id', $mid)->update(['status' => 'met', 'completed_at' => date('Y-m-d H:i:s')]);
        service('eventLedger')->record('contract', $id, 'contract.milestone_met', "Milestone met: {$ms['title']}");

        return $this->ok(['milestone_id' => $mid, 'status' => 'met']);
    }

    public function addVariation(int $id): ResponseInterface
    {
        $c = $this->mine($id);
        if (! $c) {
            return problem(404, 'not_found', 'No such contract.');
        }
        $in = $this->body();
        if (trim((string) ($in['reason'] ?? '')) === '') {
            return problem(422, 'validation_failed', 'A variation must carry a reason.');
        }
        $db  = db_connect();
        $num = (int) $db->table('contract_variations')->where('contract_id', $id)->countAllResults() + 1;
        $valueChange = (float) ($in['value_change'] ?? 0);

        $db->transBegin();
        $db->table('contract_variations')->insert([
            'contract_id' => $id, 'number' => $num, 'reason' => $in['reason'],
            'value_change' => $valueChange, 'new_end_date' => $in['new_end_date'] ?? null,
            'created_by' => (int) $this->request->userId, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $upd = ['value' => (float) $c['value'] + $valueChange];
        if (! empty($in['new_end_date'])) {
            $upd['end_date'] = $in['new_end_date'];
        }
        model(ContractModel::class)->update($id, $upd);
        $db->transCommit();

        service('eventLedger')->record('contract', $id, 'contract.variation', "Variation #{$num}: {$in['reason']}", [
            'value_change' => $valueChange,
        ]);

        return $this->ok(['variation' => $num, 'new_value' => $upd['value']], [], 201);
    }

    public function addInvoice(int $id): ResponseInterface
    {
        $c = $this->mine($id);
        if (! $c) {
            return problem(404, 'not_found', 'No such contract.');
        }
        $in  = $this->body();
        $db  = db_connect();
        $db->table('contract_invoices')->insert([
            'contract_id' => $id, 'milestone_id' => isset($in['milestone_id']) ? (int) $in['milestone_id'] : null,
            'number' => (string) ($in['number'] ?? ('INV-' . time())), 'amount' => (float) ($in['amount'] ?? 0),
            'status' => 'submitted', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        service('eventLedger')->record('contract', $id, 'contract.invoice_submitted', 'Invoice submitted', [
            'amount' => (float) ($in['amount'] ?? 0),
        ]);

        return $this->ok(['invoice_id' => $db->insertID()], [], 201);
    }
}
