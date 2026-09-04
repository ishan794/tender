<?php

namespace App\Controllers\Api\V1\Payments;

use App\Controllers\Api\V1\BaseApiController;

class RefundController extends BaseApiController
{
    /**
     * POST /api/v1/payments/refund
     * Staff endpoint to process or record subscription refunds.
     */
    public function process()
    {
        $in = $this->body();
        $rules = [
            'order_id' => 'required',
            'reason'   => 'required|min_length[5]',
        ];

        if (! $this->validateData($in, $rules)) {
            return problem(422, 'validation_failed', 'Order ID and refund reason required.', ['errors' => $this->validator->getErrors()]);
        }

        $db = db_connect();
        $order = $db->table('orders')->where('order_id', $in['order_id'])->get()->getFirstRow('array');

        if (! $order) {
            return problem(404, 'order_not_found', 'Order not found.');
        }

        if ($order['status'] === 'refunded') {
            return problem(400, 'already_refunded', 'This transaction has already been refunded.');
        }

        $db->transBegin();

        $db->table('orders')->where('order_id', $in['order_id'])->update([
            'status'         => 'refunded',
            'refund_reason'  => $in['reason'],
            'refunded_at'    => date('Y-m-d H:i:s'),
        ]);

        // Revoke paid subscription status on organization
        $db->table('organisations')->where('id', $order['org_id'])->update([
            'plan'       => 'free',
            'sub_status' => 'none',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transCommit();

        service('eventLedger')->record('order', (int) $order['id'], 'order.refunded', "Order {$in['order_id']} refunded: {$in['reason']}", [
            'order_id' => $in['order_id'],
            'amount'   => $order['amount'],
            'org_id'   => $order['org_id'],
            'reason'   => $in['reason'],
        ]);

        return $this->ok([
            'refunded'   => true,
            'order_id'   => $in['order_id'],
            'amount'     => $order['amount'],
            'message'    => 'Refund recorded successfully. Subscriber access has reverted to free tier.',
        ]);
    }
}
