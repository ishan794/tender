<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Bidder complaint / challenge workflow.
 *
 * A complaint is a formal challenge to a procurement (NPC investigation-style).
 * It is IMMUTABLE: the record can advance through its state machine but can
 * never be deleted — deletion would destroy the very evidence a challenge
 * exists to preserve. That guarantee is enforced in App\Models\ComplaintModel
 * (delete() throws), by the absence of any delete route, and every transition
 * is additionally written to the append-only event ledger.
 *
 * State machine (status):
 *   submitted → acknowledged → under_review → response_requested → decision
 *                                                   ↘ decision ↗
 *   decision → appeal → closed        decision → closed
 */
class Complaints extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'reference'             => ['type' => 'VARCHAR', 'constraint' => 40],  // CMP-<proc>-<n>
            'procurement_id'        => ['type' => 'INTEGER', 'constraint' => 11],
            'notice_ref'            => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'complainant_org_id'    => ['type' => 'INTEGER', 'constraint' => 11],
            'complainant_user_id'   => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'complainant_name'      => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'grounds'               => ['type' => 'TEXT'],
            'status'                => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'submitted'],
            'assigned_reviewer_id'  => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'assigned_reviewer_name'=> ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'response_deadline'     => ['type' => 'DATETIME', 'null' => true],
            'decision'              => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true], // upheld|rejected|partial
            'decision_reason'       => ['type' => 'TEXT', 'null' => true],
            'created_at'            => ['type' => 'DATETIME', 'null' => true],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('reference');
        $this->forge->addKey('procurement_id');
        $this->forge->addKey('complainant_org_id');
        $this->forge->addKey('status');
        $this->forge->createTable('complaints', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('complaints', true);
    }
}
