<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Procurement Event Ledger — an append-only, tamper-evident record of every
 * material action in a procurement's life.
 *
 * "Append-only" is enforced three ways, not just named:
 *   1. There is no `updated_at` / soft-delete column — the schema has no notion
 *      of a mutable or deletable row.
 *   2. The write path (App\Libraries\Audit\EventLedger) exposes only insert;
 *      it has no update or delete method.
 *   3. Each row carries `hash = sha256(prev_hash | canonical row)`, chained per
 *      entity. Editing or removing any historical row breaks every subsequent
 *      hash, so tampering is detectable by recomputing the chain — a later
 *      admin cannot silently rewrite history.
 *
 * Written with Forge so the same DDL applies on SQLite (dev) and MySQL 8 (prod).
 */
class EventLedger extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            // What the event is about. entity_type is a coarse bucket so the
            // ledger can hold planning/contract/complaint events later, not just
            // procurements.
            'entity_type' => ['type' => 'VARCHAR', 'constraint' => 32],  // procurement|notice|org|payment|contract|complaint|plan
            'entity_id'   => ['type' => 'INTEGER', 'constraint' => 11],
            'event_type'  => ['type' => 'VARCHAR', 'constraint' => 64],  // tender.approved, opening.countersigned, ...
            // Who did it — captured at write time so a later rename/deletion of
            // the user cannot erase the attribution.
            'actor_id'    => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'actor_name'  => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'actor_role'  => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'org_id'      => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'summary'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], // human-readable one-liner
            'payload'     => ['type' => 'TEXT', 'null' => true],                          // JSON detail
            'prev_hash'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'hash'        => ['type' => 'VARCHAR', 'constraint' => 64],
            'created_at'  => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['entity_type', 'entity_id']);
        $this->forge->addKey('event_type');
        $this->forge->addKey('org_id');
        $this->forge->createTable('event_ledger', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('event_ledger', true);
    }
}
