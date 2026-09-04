<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Document versioning + download log, TCO assessments, and PDPA data requests. */
class Governance extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'notice_document_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'version'            => ['type' => 'INTEGER', 'constraint' => 6],
            'sha256'             => ['type' => 'VARCHAR', 'constraint' => 64],
            'reason'             => ['type' => 'VARCHAR', 'constraint' => 220, 'null' => true],
            'effective_date'     => ['type' => 'DATE', 'null' => true],
            'superseded'         => ['type' => 'INTEGER', 'constraint' => 1, 'default' => 0],
            'uploaded_by'        => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'created_at'         => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('notice_document_id');
        $this->forge->createTable('document_versions', true);

        $this->forge->addField([
            'id'                 => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'notice_document_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'user_id'            => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'org_id'             => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'ip'                 => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'created_at'         => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('notice_document_id');
        $this->forge->createTable('document_downloads', true);

        $this->forge->addField([
            'id'             => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'procurement_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'components'     => ['type' => 'TEXT'],   // JSON of cost lines
            'total'          => ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0],
            'created_by'     => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'created_at'     => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('procurement_id');
        $this->forge->createTable('tco_assessments', true);

        $this->forge->addField([
            'id'         => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'user_id'    => ['type' => 'INTEGER', 'constraint' => 11],
            'org_id'     => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'kind'       => ['type' => 'VARCHAR', 'constraint' => 16],  // access|export|correction|deletion
            'detail'     => ['type' => 'TEXT', 'null' => true],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'received'], // received|in_progress|completed|refused
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('user_id');
        $this->forge->createTable('data_requests', true);
    }

    public function down(): void
    {
        foreach (['data_requests', 'tco_assessments', 'document_downloads', 'document_versions'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
