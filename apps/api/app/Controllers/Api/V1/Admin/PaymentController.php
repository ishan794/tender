<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use Config\Subscription;

class PaymentController extends BaseApiController
{
    public function index()
    {
        $rows = db_connect()->table('payments')
            ->select('payments.*, organisations.name AS org, users.email')
            ->join('organisations', 'organisations.id = payments.org_id')
            ->join('users', 'users.id = payments.user_id', 'left')
            ->orderBy('payments.created_at', 'DESC')->get()->getResultArray();

        foreach ($rows as &$p) {
            // Someone has paid and cannot use what they paid for, and this queue
            // decides whether they renew.
            $p['waiting_hours'] = $p['state'] === 'claimed'
                ? round((time() - strtotime($p['created_at'])) / 3600, 1) : null;
            $p['overdue'] = $p['waiting_hours'] !== null && $p['waiting_hours'] > 24;
        }

        return $this->ok($rows, ['bank' => config(Subscription::class)->bank]);
    }

    /**
     * Recording the review and activating the organisation happen in ONE
     * transaction. Splitting them is how an account ends up active with no
     * payment behind it, or a confirmed payment with a subscriber still locked
     * out and telephoning about it.
     */
    public function confirm(int $id)
    {
        $payments = model('App\Models\PaymentModel');
        $p        = $payments->find($id);

        if (! $p) {
            return problem(404, 'not_found', 'No such payment.');
        }
        if ($p['state'] !== 'claimed') {
            return problem(409, 'already_reviewed', 'This claim has already been ' . $p['state'] . '.');
        }

        $months = config(Subscription::class)->terms[$p['term']]['months'] ?? 12;
        $org    = model('App\Models\OrganisationModel')->find((int) $p['org_id']);
        $from   = ($org['renews_at'] && strtotime($org['renews_at']) > time())
            ? strtotime($org['renews_at']) : time();

        $db = db_connect();
        $db->transBegin();
        $payments->update($id, [
            'state' => 'confirmed', 'reviewed_by' => (int) $this->request->userId,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        model('App\Models\OrganisationModel')->update((int) $p['org_id'], [
            'plan' => $p['plan'], 'sub_status' => 'active',
            'renews_at' => date('Y-m-d H:i:s', strtotime("+{$months} months", $from)),
        ]);
        $db->transCommit();

        service('eventLedger')->record('payment', $id, 'payment.confirmed', "Payment #{$id} confirmed by staff #{$this->request->userId}", [
            'payment_id' => $id,
            'org_id'     => $p['org_id'],
            'plan'       => $p['plan'],
            'term'       => $p['term'],
        ]);

        return $this->ok([
            'state' => 'confirmed',
            'renews_at' => date('c', strtotime("+{$months} months", $from)),
        ]);
    }

    /** Rejection REQUIRES a reason, because the subscriber is told it verbatim. */
    public function reject(int $id)
    {
        $reason = trim((string) ($this->body()['reason'] ?? ''));
        if ($reason === '') {
            return problem(422, 'reason_required', 'A rejection must carry a reason.');
        }

        $payments = model('App\Models\PaymentModel');
        $p        = $payments->find($id);
        if (! $p) {
            return problem(404, 'not_found', 'No such payment.');
        }
        if ($p['state'] !== 'claimed') {
            return problem(409, 'already_reviewed', 'This claim has already been ' . $p['state'] . '.');
        }

        $payments->update($id, [
            'state' => 'rejected', 'reject_reason' => $reason,
            'reviewed_by' => (int) $this->request->userId, 'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        model('App\Models\OrganisationModel')->update((int) $p['org_id'], ['sub_status' => 'none']);

        service('eventLedger')->record('payment', $id, 'payment.rejected', "Payment #{$id} rejected: {$reason}", [
            'payment_id' => $id,
            'org_id'     => $p['org_id'],
            'reason'     => $reason,
        ]);

        return $this->ok(['state' => 'rejected', 'reason' => $reason]);
    }
}
