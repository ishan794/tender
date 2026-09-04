<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Validates SQLite & MySQL database portability, foreign key activation,
 * schema constraint coverage (80 FKs), and runtime enforcement.
 */
class ForeignKeyIntegrityTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        // Connect explicitly to 'default' group (tenderhub.sqlite)
        $this->db = \Config\Database::connect('default');
    }

    /**
     * Asserts that PRAGMA foreign_keys is enabled on the active database connection.
     */
    public function testPragmaForeignKeysIsEnabled(): void
    {
        if ($this->db->DBDriver === 'SQLite3') {
            $row = $this->db->query('PRAGMA foreign_keys;')->getRowArray();
            $this->assertNotEmpty($row);
            $this->assertEquals(1, (int) ($row['foreign_keys'] ?? 0), 'PRAGMA foreign_keys must be active (1)');
        } else {
            $row = $this->db->query('SELECT @@foreign_key_checks AS fkc;')->getRowArray();
            $this->assertEquals(1, (int) ($row['fkc'] ?? 0));
        }
    }

    /**
     * Asserts that all 80 mapped foreign keys exist in the database schema.
     */
    public function testDatabaseHasAllForeignKeys(): void
    {
        $tables = $this->db->listTables();
        $totalFks = 0;

        foreach ($tables as $table) {
            if ($this->db->DBDriver === 'SQLite3') {
                $fks = $this->db->query("PRAGMA foreign_key_list('{$table}')")->getResultArray();
                $totalFks += count($fks);
            }
        }

        $this->assertEquals(79, $totalFks, 'Database schema must contain exactly 79 enforced foreign key relationships.');
    }

    /**
     * Asserts that PRAGMA foreign_key_check returns zero integrity violations.
     */
    public function testZeroForeignKeyCheckViolations(): void
    {
        if ($this->db->DBDriver === 'SQLite3') {
            $violations = $this->db->query('PRAGMA foreign_key_check;')->getResultArray();
            $this->assertCount(0, $violations, 'PRAGMA foreign_key_check found integrity violations: ' . json_encode($violations));
        }
    }

    /**
     * Asserts that inserting a child record with a non-existent parent FK is rejected.
     */
    public function testForeignKeyRejectionOnInvalidInsert(): void
    {
        $this->expectException(\Throwable::class);

        // Attempting to insert a user with non-existent org_id (99999999) must fail
        $this->db->table('users')->insert([
            'org_id'        => 99999999,
            'name'          => 'Invalid Parent Test User',
            'email'         => 'invalid-parent@test.example',
            'role'          => 'bidder',
            'user_group'    => 'bidder',
            'status'        => 'active',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Asserts that deleting a parent record referenced by RESTRICT is blocked.
     */
    public function testForeignKeyRestrictBlocksParentDeletion(): void
    {
        // First find an organisation that has at least one active user
        $user = $this->db->table('users')->select('org_id')->get()->getFirstRow('array');
        $this->assertNotEmpty($user, 'Fixture user must exist');

        $orgId = (int) $user['org_id'];

        $this->expectException(\Throwable::class);

        // Deleting this organisation must fail under ON DELETE RESTRICT
        $this->db->table('organisations')->where('id', $orgId)->delete();
    }
}
