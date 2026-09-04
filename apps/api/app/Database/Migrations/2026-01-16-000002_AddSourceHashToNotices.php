<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds source_hash column to notices table for crawler/gazette deduplication.
 */
class AddSourceHashToNotices extends Migration
{
    public function up(): void
    {
        $fields = [
            'source_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'source_id',
            ],
        ];

        $this->forge->addColumn('notices', $fields);
    }

    public function down(): void
    {
        $this->forge->dropColumn('notices', 'source_hash');
    }
}
