<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sealed-bid encryption at rest (envelope scheme).
 *
 *   tender_keys : one Data Encryption Key (DEK) per tender, stored WRAPPED
 *                 (encrypted) under the master key. The plaintext DEK never
 *                 touches the database.
 *   bid_seals   : each bid's sensitive fields (bidder identity, price, security)
 *                 AES-256-GCM encrypted under the tender DEK. A database dump
 *                 yields only ciphertext; decryption is possible only with the
 *                 master key, and only after the dual-control opening unwraps
 *                 the DEK.
 *
 * The master key is provided by the environment locally; in production it is
 * held/wrapped by a KMS (see CryptoService) — that external custody is the one
 * part marked BLOCKED — KMS INFRASTRUCTURE REQUIRED.
 */
class BidEncryption extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'procurement_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'wrapped_dek'    => ['type' => 'TEXT'],  // base64 ciphertext of the DEK
            'iv'             => ['type' => 'VARCHAR', 'constraint' => 32],
            'tag'            => ['type' => 'VARCHAR', 'constraint' => 32],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('procurement_id');
        $this->forge->createTable('tender_keys', true);

        $this->forge->addField([
            'id'             => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'procurement_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'submission_id'  => ['type' => 'INTEGER', 'constraint' => 11],
            'ciphertext'     => ['type' => 'TEXT'],
            'iv'             => ['type' => 'VARCHAR', 'constraint' => 32],
            'tag'            => ['type' => 'VARCHAR', 'constraint' => 32],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('procurement_id');
        $this->forge->addKey('submission_id');
        $this->forge->createTable('bid_seals', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('bid_seals', true);
        $this->forge->dropTable('tender_keys', true);
    }
}
