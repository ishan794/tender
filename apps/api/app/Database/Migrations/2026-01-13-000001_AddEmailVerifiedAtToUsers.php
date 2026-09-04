<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds email_verified_at column to users table to support the full email verification lifecycle.
 */
class AddEmailVerifiedAtToUsers extends Migration
{
    public function up(): void
    {
        $fields = [
            'email_verified_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'status',
            ],
        ];

        $this->forge->addColumn('users', $fields);
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', 'email_verified_at');
    }
}
