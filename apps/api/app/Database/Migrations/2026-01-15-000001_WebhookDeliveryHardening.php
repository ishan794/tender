<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Hardens webhooks and webhook_deliveries tables with encrypted secret storage,
 * idempotency keys, delivery response metrics, retry scheduling, and error tracking.
 */
class WebhookDeliveryHardening extends Migration
{
    public function up(): void
    {
        // 1. webhooks: add secret_ciphertext
        $this->forge->addColumn('webhooks', [
            'secret_ciphertext' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        // 2. webhook_deliveries: add delivery tracking columns
        $this->forge->addColumn('webhook_deliveries', [
            'idempotency_key' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => true,
            ],
            'response_code' => [
                'type' => 'INTEGER',
                'constraint' => 5,
                'null' => true,
            ],
            'response_body' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'delivered_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'next_retry_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_error' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('webhooks', 'secret_ciphertext');
        $this->forge->dropColumn('webhooks', 'updated_at');

        $this->forge->dropColumn('webhook_deliveries', 'idempotency_key');
        $this->forge->dropColumn('webhook_deliveries', 'response_code');
        $this->forge->dropColumn('webhook_deliveries', 'response_body');
        $this->forge->dropColumn('webhook_deliveries', 'delivered_at');
        $this->forge->dropColumn('webhook_deliveries', 'next_retry_at');
        $this->forge->dropColumn('webhook_deliveries', 'last_error');
    }
}
