<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 2026-01-11-000001_MissingP0Tables
 * Creates orders, password_resets, email_verifications, and debarred_suppliers tables.
 */
class MissingP0Tables extends Migration
{
    public function up(): void
    {
        // 1. orders
        $this->forge->addField([
            'id'             => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'order_id'       => ['type' => 'VARCHAR', 'constraint' => 64],
            'org_id'         => ['type' => 'INTEGER', 'constraint' => 11],
            'user_id'        => ['type' => 'INTEGER', 'constraint' => 11],
            'plan'           => ['type' => 'VARCHAR', 'constraint' => 32],
            'amount'         => ['type' => 'DECIMAL', 'constraint' => '12,2'],
            'currency'       => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'LKR'],
            'gateway'        => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'payhere'],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'pending'],
            'transaction_id' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'created_at'     => ['type' => 'DATETIME'],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('order_id');
        $this->forge->addKey('org_id');
        $this->forge->addKey('user_id');
        $this->forge->addKey('status');
        $this->forge->createTable('orders', true);

        // 2. password_resets
        $this->forge->addField([
            'id'         => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'email'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'token_hash' => ['type' => 'VARCHAR', 'constraint' => 64],
            'expires_at' => ['type' => 'DATETIME'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('email');
        $this->forge->addKey('token_hash');
        $this->forge->addKey('expires_at');
        $this->forge->createTable('password_resets', true);

        // 3. email_verifications
        $this->forge->addField([
            'id'         => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'email'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'token_hash' => ['type' => 'VARCHAR', 'constraint' => 64],
            'expires_at' => ['type' => 'DATETIME'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('email');
        $this->forge->addKey('token_hash');
        $this->forge->addKey('expires_at');
        $this->forge->createTable('email_verifications', true);

        // 4. debarred_suppliers
        $this->forge->addField([
            'id'          => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'identifier'  => ['type' => 'VARCHAR', 'constraint' => 64],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'active'],
            'reason'      => ['type' => 'TEXT', 'null' => true],
            'gazette_ref' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'starts_at'   => ['type' => 'DATE'],
            'ends_at'     => ['type' => 'DATE'],
            'created_at'  => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('identifier');
        $this->forge->addKey('status');
        $this->forge->addKey('ends_at');
        $this->forge->createTable('debarred_suppliers', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('debarred_suppliers', true);
        $this->forge->dropTable('email_verifications', true);
        $this->forge->dropTable('password_resets', true);
        $this->forge->dropTable('orders', true);
    }
}