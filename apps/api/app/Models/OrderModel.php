<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * OrderModel
 * Manages orders and enforces strict state machine transitions:
 *   pending ──> paid | failed | expired
 * Illegal transitions (e.g. paid -> failed, failed -> paid) are strictly rejected.
 */
class OrderModel extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID    = 'paid';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_EXPIRED = 'expired';

    /**
     * Map of allowable status transitions.
     * Terminal states (paid, failed, expired) cannot transition to any other state.
     */
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_PENDING => [
            self::STATUS_PAID,
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
        ],
        self::STATUS_PAID    => [], // Terminal: once paid, cannot be downgraded or altered
        self::STATUS_FAILED  => [], // Terminal: failed orders require a fresh checkout
        self::STATUS_EXPIRED => [], // Terminal: expired orders cannot be revived
    ];

    protected $DBGroup       = 'default';
    protected $table         = 'orders';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'order_id',
        'org_id',
        'user_id',
        'plan',
        'amount',
        'currency',
        'gateway',
        'status',
        'transaction_id',
        'created_at',
        'updated_at',
    ];

    /**
     * Evaluates whether a state transition is permitted.
     */
    public static function canTransition(string $fromStatus, string $toStatus): bool
    {
        if ($fromStatus === $toStatus) {
            return true; // Idempotent same-state transition
        }

        $allowed = self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];
        return in_array($toStatus, $allowed, true);
    }

    /**
     * Executes a state transition with strict validation and idempotency.
     *
     * @param string|int $orderId Order code (string) or primary key (int)
     * @param string $newStatus Target status
     * @param array $extraFields Optional metadata fields to update (e.g. transaction_id)
     * @return array {order: array, changed: bool, idempotent: bool}
     * @throws \RuntimeException If order not found
     * @throws \DomainException If transition is illegal
     */
    public function transition(string|int $orderId, string $newStatus, array $extraFields = []): array
    {
        $order = is_int($orderId) || ctype_digit((string) $orderId)
            ? $this->find($orderId)
            : $this->where('order_id', (string) $orderId)->first();

        if (! $order) {
            throw new \RuntimeException("Order '{$orderId}' not found.");
        }

        $currentStatus = $order['status'];

        // Idempotent call (already in target state)
        if ($currentStatus === $newStatus) {
            return [
                'order'      => $order,
                'changed'    => false,
                'idempotent' => true,
            ];
        }

        // Validate state machine rule
        if (! self::canTransition($currentStatus, $newStatus)) {
            throw new \DomainException("Illegal order state transition: cannot transition order '{$order['order_id']}' from '{$currentStatus}' to '{$newStatus}'.");
        }

        $updateData = array_merge($extraFields, [
            'status'     => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->update($order['id'], $updateData);

        $updatedOrder = $this->find($order['id']);

        return [
            'order'      => $updatedOrder,
            'changed'    => true,
            'idempotent' => false,
        ];
    }
}
