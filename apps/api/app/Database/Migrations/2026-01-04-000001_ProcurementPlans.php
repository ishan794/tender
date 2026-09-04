<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Annual procurement planning. Each row is one PLANNED procurement line in an
 * organisation's annual plan. The plan advances draft → submitted → approved,
 * can be revised (a new row linked by revision_of, the old one marked
 * 'revised'), and is linked to the real tender once it is created
 * (linked_procurement_id) so plan-vs-actual can be computed.
 */
class ProcurementPlans extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'org_id'                => ['type' => 'INTEGER', 'constraint' => 11],
            'year'                  => ['type' => 'INTEGER', 'constraint' => 4],
            'title'                 => ['type' => 'VARCHAR', 'constraint' => 220],
            'department'            => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
            'project'               => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
            'category_id'           => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'estimated_value'       => ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0],
            'funding_source'        => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'procurement_method'    => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'open'],
            'planned_tender_date'   => ['type' => 'DATE', 'null' => true],
            'planned_award_date'    => ['type' => 'DATE', 'null' => true],
            'budget_allocation'     => ['type' => 'DECIMAL', 'constraint' => '18,2', 'null' => true],
            'officer_id'            => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'officer_name'          => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'status'                => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'draft'], // draft|submitted|approved|revised
            'linked_procurement_id' => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'revision_of'           => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'created_by'            => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'approved_by'           => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'approved_at'           => ['type' => 'DATETIME', 'null' => true],
            'created_at'            => ['type' => 'DATETIME', 'null' => true],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['org_id', 'year']);
        $this->forge->addKey('status');
        $this->forge->createTable('procurement_plans', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('procurement_plans', true);
    }
}
