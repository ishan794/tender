<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Vendor KYC / verification. An organisation SUBMITS documents (→ pending); a
 * STAFF reviewer approves/rejects/suspends. The organisation can never set its
 * own verify_state to verified — submission only ever moves it to 'pending'.
 * organisations.verify_state carries: unverified|pending|verified|rejected|
 * suspended|expired.
 */
class KycSubmissions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'org_id'        => ['type' => 'INTEGER', 'constraint' => 11],
            'submitted_by'  => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'categories'    => ['type' => 'TEXT', 'null' => true],  // JSON: which doc categories provided
            'notes'         => ['type' => 'TEXT', 'null' => true],
            'status'        => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'pending'], // pending|approved|rejected
            'reviewer_id'   => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'reviewer_name' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'reason'        => ['type' => 'TEXT', 'null' => true],
            'submitted_at'  => ['type' => 'DATETIME', 'null' => true],
            'reviewed_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('org_id');
        $this->forge->addKey('status');
        $this->forge->createTable('kyc_submissions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('kyc_submissions', true);
    }
}
