<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Rev 3.0 Foreign Key Enforcement Migration
 *
 * Enforces relational integrity across all 46 relational tables (80 FK relationships).
 * Operates portably across both SQLite3 (development) and MySQL 8 (production).
 *
 * Deletion Semantics:
 * - RESTRICT: Default for auditable procurement, regulatory, or financial records.
 * - CASCADE: Subordinate lifecycle records owned by parent record.
 * - SET NULL: Optional taxonomic links (categories, regional districts/provinces).
 */
class EnforceForeignKeys extends Migration
{
    private const FOREIGN_KEYS = [
        [
            'table'      => 'addenda',
            'column'     => 'procurement_id',
            'ref_table'  => 'procurements',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'alert_profiles',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'alert_profiles',
            'column'     => 'user_id',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'api_keys',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'auction_lots',
            'column'     => 'notice_id',
            'ref_table'  => 'notices',
            'ref_column' => 'id',
            'on_delete'  => 'CASCADE',
        ],
        [
            'table'      => 'awards',
            'column'     => 'procurement_id',
            'ref_table'  => 'procurements',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'awards',
            'column'     => 'submission_id',
            'ref_table'  => 'submissions',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'awards',
            'column'     => 'supplier_org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'bid_seals',
            'column'     => 'procurement_id',
            'ref_table'  => 'procurements',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'bid_seals',
            'column'     => 'submission_id',
            'ref_table'  => 'submissions',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'bids',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'bids',
            'column'     => 'notice_id',
            'ref_table'  => 'notices',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'categories',
            'column'     => 'parent_id',
            'ref_table'  => 'categories',
            'ref_column' => 'id',
            'on_delete'  => 'SET NULL',
        ],
        [
            'table'      => 'clarifications',
            'column'     => 'procurement_id',
            'ref_table'  => 'procurements',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'coi_declarations',
            'column'     => 'procurement_id',
            'ref_table'  => 'procurements',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'coi_declarations',
            'column'     => 'user_id',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'complaints',
            'column'     => 'procurement_id',
            'ref_table'  => 'procurements',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'contract_invoices',
            'column'     => 'contract_id',
            'ref_table'  => 'contracts',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'contract_milestones',
            'column'     => 'contract_id',
            'ref_table'  => 'contracts',
            'ref_column' => 'id',
            'on_delete'  => 'CASCADE',
        ],
        [
            'table'      => 'contract_variations',
            'column'     => 'contract_id',
            'ref_table'  => 'contracts',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'contract_variations',
            'column'     => 'created_by',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'contracts',
            'column'     => 'procurement_id',
            'ref_table'  => 'procurements',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'contracts',
            'column'     => 'award_id',
            'ref_table'  => 'awards',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'contracts',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'contracts',
            'column'     => 'supplier_org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'contracts',
            'column'     => 'created_by',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'data_requests',
            'column'     => 'user_id',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'data_requests',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'districts',
            'column'     => 'province_id',
            'ref_table'  => 'provinces',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'doc_purchases',
            'column'     => 'procurement_id',
            'ref_table'  => 'procurements',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'doc_purchases',
            'column'     => 'buyer_org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'document_assets',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'document_downloads',
            'column'     => 'notice_document_id',
            'ref_table'  => 'notice_documents',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'document_downloads',
            'column'     => 'user_id',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'document_downloads',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'document_versions',
            'column'     => 'notice_document_id',
            'ref_table'  => 'notice_documents',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'eval_criteria',
            'column'     => 'procurement_id',
            'ref_table'  => 'procurements',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'eval_scores',
            'column'     => 'submission_id',
            'ref_table'  => 'submissions',
            'ref_column' => 'id',
            'on_delete'  => 'CASCADE',
        ],
        [
            'table'      => 'event_ledger',
            'column'     => 'actor_id',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'event_ledger',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'invitations',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'kyc_submissions',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'legal_holds',
            'column'     => 'created_by',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'notice_documents',
            'column'     => 'notice_id',
            'ref_table'  => 'notices',
            'ref_column' => 'id',
            'on_delete'  => 'CASCADE',
        ],
        [
            'table'      => 'notices',
            'column'     => 'authority_id',
            'ref_table'  => 'authorities',
            'ref_column' => 'id',
            'on_delete'  => 'SET NULL',
        ],
        [
            'table'      => 'notices',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'notices',
            'column'     => 'category_id',
            'ref_table'  => 'categories',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'notices',
            'column'     => 'district_id',
            'ref_table'  => 'districts',
            'ref_column' => 'id',
            'on_delete'  => 'SET NULL',
        ],
        [
            'table'      => 'notices',
            'column'     => 'source_id',
            'ref_table'  => 'feed_sources',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'notices',
            'column'     => 'canonical_id',
            'ref_table'  => 'notices',
            'ref_column' => 'id',
            'on_delete'  => 'SET NULL',
        ],
        [
            'table'      => 'notification_deliveries',
            'column'     => 'notification_id',
            'ref_table'  => 'notifications',
            'ref_column' => 'id',
            'on_delete'  => 'CASCADE',
        ],
        [
            'table'      => 'notifications',
            'column'     => 'user_id',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'notifications',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'orders',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'orders',
            'column'     => 'user_id',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'organisations',
            'column'     => 'district_id',
            'ref_table'  => 'districts',
            'ref_column' => 'id',
            'on_delete'  => 'SET NULL',
        ],
        [
            'table'      => 'payments',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'payments',
            'column'     => 'user_id',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'procurement_plans',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'procurement_plans',
            'column'     => 'category_id',
            'ref_table'  => 'categories',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'procurement_plans',
            'column'     => 'created_by',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'procurement_plans',
            'column'     => 'approved_by',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'procurements',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'procurements',
            'column'     => 'notice_id',
            'ref_table'  => 'notices',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'procurements',
            'column'     => 'created_by',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'procurements',
            'column'     => 'approved_by',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'procurements',
            'column'     => 'published_by',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'ratings',
            'column'     => 'award_id',
            'ref_table'  => 'awards',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'refresh_tokens',
            'column'     => 'user_id',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'CASCADE',
        ],
        [
            'table'      => 'security_events',
            'column'     => 'actor_id',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'SET NULL',
        ],
        [
            'table'      => 'signatures',
            'column'     => 'signer_id',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'signatures',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'submissions',
            'column'     => 'procurement_id',
            'ref_table'  => 'procurements',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'tco_assessments',
            'column'     => 'procurement_id',
            'ref_table'  => 'procurements',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'tco_assessments',
            'column'     => 'created_by',
            'ref_table'  => 'users',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'tender_keys',
            'column'     => 'procurement_id',
            'ref_table'  => 'procurements',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'users',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
        [
            'table'      => 'webhook_deliveries',
            'column'     => 'webhook_id',
            'ref_table'  => 'webhooks',
            'ref_column' => 'id',
            'on_delete'  => 'CASCADE',
        ],
        [
            'table'      => 'webhooks',
            'column'     => 'org_id',
            'ref_table'  => 'organisations',
            'ref_column' => 'id',
            'on_delete'  => 'RESTRICT',
        ],
    ];

    public function up(): void
    {
        if ($this->db->DBDriver === 'SQLite3') {
            $this->upSqlite();
        } else {
            $this->upMysql();
        }
    }

    public function down(): void
    {
        if ($this->db->DBDriver === 'SQLite3') {
            $this->downSqlite();
        } else {
            $this->downMysql();
        }
    }

    private function upSqlite(): void
    {
        $this->db->query('PRAGMA foreign_keys = OFF');

        $byTable = [];
        foreach (self::FOREIGN_KEYS as $fk) {
            $byTable[$fk['table']][] = $fk;
        }

        foreach ($byTable as $table => $tableFks) {
            if (! $this->db->tableExists($table)) {
                continue;
            }

            $row = $this->db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->getRowArray();
            if (! $row || empty($row['sql'])) {
                continue;
            }
            $origSql = $row['sql'];

            $indexes = $this->db->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}' AND sql IS NOT NULL")->getResultArray();

            $constraints = [];
            foreach ($tableFks as $fk) {
                $col    = $fk['column'];
                $refTbl = $fk['ref_table'];
                $refCol = $fk['ref_column'];
                $onDel  = $fk['on_delete'];
                $fkName = "fk_{$table}_{$col}";
                $constraints[] = "\tCONSTRAINT `{$fkName}` FOREIGN KEY (`{$col}`) REFERENCES `{$refTbl}`(`{$refCol}`) ON DELETE {$onDel} ON UPDATE CASCADE";
            }

            $pos = strrpos(rtrim($origSql), ')');
            $newSql = substr($origSql, 0, $pos) . ",\n" . implode(",\n", $constraints) . "\n)";

            $this->db->transStart();
            $tempTbl = "temp_rebuild_{$table}";
            $tempSql = preg_replace('/CREATE TABLE [`"]?' . preg_quote($table, '/') . '[`"]?/i', "CREATE TABLE `{$tempTbl}`", $newSql, 1);
            $this->db->query($tempSql);

            $this->db->query("INSERT INTO `{$tempTbl}` SELECT * FROM `{$table}`");
            $this->db->query("DROP TABLE `{$table}`");
            $this->db->query("ALTER TABLE `{$tempTbl}` RENAME TO `{$table}`");

            foreach ($indexes as $idx) {
                if (! empty($idx['sql'])) {
                    $this->db->query($idx['sql']);
                }
            }
            $this->db->transComplete();
        }

        $this->db->query('PRAGMA foreign_keys = ON');

        $violations = $this->db->query('PRAGMA foreign_key_check')->getResultArray();
        if (! empty($violations)) {
            throw new \RuntimeException('Foreign key integrity violations detected: ' . json_encode($violations));
        }
    }

    private function downSqlite(): void
    {
        $this->db->query('PRAGMA foreign_keys = OFF');

        $byTable = [];
        foreach (self::FOREIGN_KEYS as $fk) {
            $byTable[$fk['table']][] = $fk;
        }

        foreach ($byTable as $table => $tableFks) {
            if (! $this->db->tableExists($table)) {
                continue;
            }

            $row = $this->db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'")->getRowArray();
            if (! $row || empty($row['sql'])) {
                continue;
            }
            $origSql = $row['sql'];

            $indexes = $this->db->query("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='{$table}' AND sql IS NOT NULL")->getResultArray();

            $lines = explode("\n", $origSql);
            $filtered = [];
            foreach ($lines as $line) {
                if (str_contains($line, 'FOREIGN KEY') && str_contains($line, 'REFERENCES')) {
                    continue;
                }
                $filtered[] = $line;
            }
            $newSql = implode("\n", $filtered);
            $newSql = preg_replace('/,(\s*\))$/', '$1', $newSql);

            $this->db->transStart();
            $tempTbl = "temp_rebuild_{$table}";
            $tempSql = preg_replace('/CREATE TABLE [`"]?' . preg_quote($table, '/') . '[`"]?/i', "CREATE TABLE `{$tempTbl}`", $newSql, 1);
            $this->db->query($tempSql);

            $this->db->query("INSERT INTO `{$tempTbl}` SELECT * FROM `{$table}`");
            $this->db->query("DROP TABLE `{$table}`");
            $this->db->query("ALTER TABLE `{$tempTbl}` RENAME TO `{$table}`");

            foreach ($indexes as $idx) {
                if (! empty($idx['sql'])) {
                    $this->db->query($idx['sql']);
                }
            }
            $this->db->transComplete();
        }

        $this->db->query('PRAGMA foreign_keys = ON');
    }

    private function upMysql(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::FOREIGN_KEYS as $fk) {
            $table  = $fk['table'];
            $col    = $fk['column'];
            $refTbl = $fk['ref_table'];
            $refCol = $fk['ref_column'];
            $onDel  = $fk['on_delete'];
            $fkName = "fk_{$table}_{$col}";

            $sql = "ALTER TABLE `{$table}` ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`{$col}`) REFERENCES `{$refTbl}`(`{$refCol}`) ON DELETE {$onDel} ON UPDATE CASCADE";
            $this->db->query($sql);
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function downMysql(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::FOREIGN_KEYS as $fk) {
            $table  = $fk['table'];
            $col    = $fk['column'];
            $fkName = "fk_{$table}_{$col}";

            $this->db->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fkName}`");
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}