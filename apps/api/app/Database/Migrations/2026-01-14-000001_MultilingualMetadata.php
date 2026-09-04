<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds multilingual (Sinhala and Tamil) metadata columns to notices, categories,
 * authorities, and districts in accordance with Sri Lanka trilingual requirements.
 */
class MultilingualMetadata extends Migration
{
    public function up(): void
    {
        // 1. notices table: add summary and description translations
        $this->forge->addColumn('notices', [
            'summary_si' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'summary_ta' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'description_si' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'description_ta' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);

        // 2. categories table: add name translations
        $this->forge->addColumn('categories', [
            'name_si' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
                'null' => true,
            ],
            'name_ta' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
                'null' => true,
            ],
        ]);

        // 3. authorities table: add name translations
        $this->forge->addColumn('authorities', [
            'name_si' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'name_ta' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
        ]);

        // 4. districts table: add name translations
        $this->forge->addColumn('districts', [
            'name_si' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
                'null' => true,
            ],
            'name_ta' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
                'null' => true,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('notices', 'summary_si');
        $this->forge->dropColumn('notices', 'summary_ta');
        $this->forge->dropColumn('notices', 'description_si');
        $this->forge->dropColumn('notices', 'description_ta');

        $this->forge->dropColumn('categories', 'name_si');
        $this->forge->dropColumn('categories', 'name_ta');

        $this->forge->dropColumn('authorities', 'name_si');
        $this->forge->dropColumn('authorities', 'name_ta');

        $this->forge->dropColumn('districts', 'name_si');
        $this->forge->dropColumn('districts', 'name_ta');
    }
}
