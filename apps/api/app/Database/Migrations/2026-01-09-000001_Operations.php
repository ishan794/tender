<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Operational tables: security events, in-app notifications + delivery logs,
 * legal holds, and webhook delivery logs.
 */
class Operations extends Migration
{
    public function up(): void
    {
        // Security / monitoring events
        $this->forge->addField([
            'id'         => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'kind'       => ['type' => 'VARCHAR', 'constraint' => 48],  // auth_failure|authz_failure|token_reuse|rate_limited|...
            'severity'   => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'info'],
            'actor_id'   => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'ip'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'detail'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('kind');
        $this->forge->addKey('created_at');
        $this->forge->createTable('security_events', true);

        // In-app notifications
        $this->forge->addField([
            'id'         => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'user_id'    => ['type' => 'INTEGER', 'constraint' => 11],
            'org_id'     => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'type'       => ['type' => 'VARCHAR', 'constraint' => 48],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 200],
            'body'       => ['type' => 'TEXT', 'null' => true],
            'link'       => ['type' => 'VARCHAR', 'constraint' => 220, 'null' => true],
            'read_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['user_id', 'read_at']);
        $this->forge->createTable('notifications', true);

        // Delivery log across channels (in-app always; email/sms/whatsapp = boundary)
        $this->forge->addField([
            'id'              => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'notification_id' => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'channel'         => ['type' => 'VARCHAR', 'constraint' => 16],  // in_app|email|sms|whatsapp
            'status'          => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'queued'], // queued|sent|delivered|failed|skipped
            'detail'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'      => ['type' => 'DATETIME'],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('notification_id');
        $this->forge->createTable('notification_deliveries', true);

        // Legal holds (block deletion of held records)
        $this->forge->addField([
            'id'          => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'entity_type' => ['type' => 'VARCHAR', 'constraint' => 32],
            'entity_id'   => ['type' => 'INTEGER', 'constraint' => 11],
            'reason'      => ['type' => 'TEXT'],
            'created_by'  => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'created_name'=> ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'released_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'  => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['entity_type', 'entity_id']);
        $this->forge->createTable('legal_holds', true);

        // Webhook delivery log
        $this->forge->addField([
            'id'         => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'webhook_id' => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'event'      => ['type' => 'VARCHAR', 'constraint' => 48],
            'payload'    => ['type' => 'TEXT', 'null' => true],
            'signature'  => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'queued'],
            'attempts'   => ['type' => 'INTEGER', 'constraint' => 4, 'default' => 0],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('webhook_deliveries', true);
    }

    public function down(): void
    {
        foreach (['webhook_deliveries', 'legal_holds', 'notification_deliveries', 'notifications', 'security_events'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
