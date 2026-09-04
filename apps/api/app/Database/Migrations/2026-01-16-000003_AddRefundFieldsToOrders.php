<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds refund_reason and refunded_at columns to orders table.
 */
class AddRefundFieldsToOrders extends Migration
{
    public function up(): void
    {
        $fields = [
            'refund_reason' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'transaction_id',
            ],
            'refunded_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'refund_reason',
            ],
        ];

        $this->forge->addColumn('orders', $fields);
    }

    public function down(): void
    {
        $this->forge->dropColumn('orders', 'refund_reason');
        $this->forge->dropColumn('orders', 'refunded_at');
    }
}
