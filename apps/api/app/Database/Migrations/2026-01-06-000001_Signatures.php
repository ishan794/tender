<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Digital signing events. Each signature binds a signer, a timestamp, and a
 * SHA-256 hash of the signed payload under an HMAC (the server signing key).
 *
 * SCOPE: this is an application-level cryptographic attestation — tamper-evident
 * and attributable — NOT a legally recognised electronic signature under the
 * Sri Lanka Electronic Transactions Act. Binding to an accredited certification
 * authority is a separate, external integration (see SigningService docblock).
 */
class Signatures extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'entity_type' => ['type' => 'VARCHAR', 'constraint' => 32],
            'entity_id'   => ['type' => 'INTEGER', 'constraint' => 11],
            'event'       => ['type' => 'VARCHAR', 'constraint' => 48],  // approval|publication|addendum|opening|award|contract
            'signer_id'   => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'signer_name' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'signer_role' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'org_id'      => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'doc_hash'    => ['type' => 'VARCHAR', 'constraint' => 64],
            'signature'   => ['type' => 'VARCHAR', 'constraint' => 64],
            'signed_at'   => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['entity_type', 'entity_id']);
        $this->forge->createTable('signatures', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('signatures', true);
    }
}
