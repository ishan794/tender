<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates ingestion_runs table to track gazette and web crawler execution metrics,
 * items discovered, inserted, skipped, failure reasons, and duration.
 */
class IngestionRuns extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INTEGER', 'auto_increment' => true, 'constraint' => 11],
            'source_id'      => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'mode'           => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'scrape'],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'running'],
            'items_found'    => ['type' => 'INTEGER', 'constraint' => 11, 'default' => 0],
            'items_inserted' => ['type' => 'INTEGER', 'constraint' => 11, 'default' => 0],
            'items_skipped'  => ['type' => 'INTEGER', 'constraint' => 11, 'default' => 0],
            'error_message'  => ['type' => 'TEXT', 'null' => true],
            'duration_ms'    => ['type' => 'INTEGER', 'constraint' => 11, 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('source_id');
        $this->forge->addForeignKey('source_id', 'feed_sources', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('ingestion_runs', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('ingestion_runs', true);
    }
}
