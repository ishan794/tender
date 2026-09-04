<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Contract management — the lifecycle AFTER award. A contract is created from
 * an award and tracks milestones, variations, invoices, performance security,
 * retention, and closure. Every material change is written to the event ledger.
 */
class Contracts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                   => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'contract_no'          => ['type' => 'VARCHAR', 'constraint' => 60],
            'procurement_id'       => ['type' => 'INTEGER', 'constraint' => 11],
            'award_id'             => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'org_id'               => ['type' => 'INTEGER', 'constraint' => 11],
            'supplier_org_id'      => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'supplier_name'        => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'title'                => ['type' => 'VARCHAR', 'constraint' => 220],
            'value'                => ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0],
            'start_date'           => ['type' => 'DATE', 'null' => true],
            'end_date'             => ['type' => 'DATE', 'null' => true],
            'performance_security' => ['type' => 'DECIMAL', 'constraint' => '18,2', 'null' => true],
            'retention_pct'        => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'status'               => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'draft'], // draft|active|suspended|completed|closed|terminated
            'created_by'           => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'created_at'           => ['type' => 'DATETIME', 'null' => true],
            'updated_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('contract_no');
        $this->forge->addKey('org_id');
        $this->forge->addKey('procurement_id');
        $this->forge->createTable('contracts', true);

        $this->forge->addField([
            'id'           => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'contract_id'  => ['type' => 'INTEGER', 'constraint' => 11],
            'title'        => ['type' => 'VARCHAR', 'constraint' => 220],
            'due_date'     => ['type' => 'DATE', 'null' => true],
            'amount'       => ['type' => 'DECIMAL', 'constraint' => '18,2', 'null' => true],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'pending'], // pending|met|missed
            'completed_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('contract_id');
        $this->forge->createTable('contract_milestones', true);

        $this->forge->addField([
            'id'           => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'contract_id'  => ['type' => 'INTEGER', 'constraint' => 11],
            'number'       => ['type' => 'INTEGER', 'constraint' => 6],
            'reason'       => ['type' => 'TEXT'],
            'value_change' => ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0],
            'new_end_date' => ['type' => 'DATE', 'null' => true],
            'created_by'   => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('contract_id');
        $this->forge->createTable('contract_variations', true);

        $this->forge->addField([
            'id'           => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'contract_id'  => ['type' => 'INTEGER', 'constraint' => 11],
            'milestone_id' => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'number'       => ['type' => 'VARCHAR', 'constraint' => 40],
            'amount'       => ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'submitted'], // submitted|approved|paid
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('contract_id');
        $this->forge->createTable('contract_invoices', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('contract_invoices', true);
        $this->forge->dropTable('contract_variations', true);
        $this->forge->dropTable('contract_milestones', true);
        $this->forge->dropTable('contracts', true);
    }
}
