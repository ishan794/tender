<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

class MissingP0TablesTest extends CIUnitTestCase
{
    public function testMissingTablesExistInDatabase(): void
    {
        $db = \Config\Database::connect('default');
        $this->assertTrue($db->tableExists('orders'), 'Table orders must exist.');
        $this->assertTrue($db->tableExists('password_resets'), 'Table password_resets must exist.');
        $this->assertTrue($db->tableExists('email_verifications'), 'Table email_verifications must exist.');
        $this->assertTrue($db->tableExists('debarred_suppliers'), 'Table debarred_suppliers must exist.');
    }

    public function testOrdersTableCanInsertAndRetrieve(): void
    {
        $db = \Config\Database::connect('default');
        $orderId = 'ORD-TEST-' . bin2hex(random_bytes(4));
        
        $inserted = $db->table('orders')->insert([
            'order_id'   => $orderId,
            'org_id'     => 1,
            'user_id'    => 2,
            'plan'       => 'business',
            'amount'     => 15000.00,
            'currency'   => 'LKR',
            'gateway'    => 'payhere',
            'status'     => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertTrue($inserted);

        $row = $db->table('orders')->where('order_id', $orderId)->get()->getFirstRow('array');
        $this->assertNotNull($row);
        $this->assertSame($orderId, $row['order_id']);
        $this->assertSame('business', $row['plan']);

        // Clean up test order
        $db->table('orders')->where('order_id', $orderId)->delete();
    }

    public function testPasswordResetsTableCanInsertAndRetrieve(): void
    {
        $db = \Config\Database::connect('default');
        $email = 'test_reset@tenderhub.lk';
        $tokenHash = hash('sha256', 'sample_secret_token');
        
        $inserted = $db->table('password_resets')->insert([
            'email'      => $email,
            'token_hash' => $tokenHash,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertTrue($inserted);
        $row = $db->table('password_resets')->where('email', $email)->get()->getFirstRow('array');
        $this->assertNotNull($row);
        $this->assertSame($tokenHash, $row['token_hash']);

        // Clean up
        $db->table('password_resets')->where('email', $email)->delete();
    }

    public function testEmailVerificationsTableCanInsertAndRetrieve(): void
    {
        $db = \Config\Database::connect('default');
        $email = 'test_verify@tenderhub.lk';
        $tokenHash = hash('sha256', 'sample_verify_token');
        
        $inserted = $db->table('email_verifications')->insert([
            'email'      => $email,
            'token_hash' => $tokenHash,
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertTrue($inserted);
        $row = $db->table('email_verifications')->where('email', $email)->get()->getFirstRow('array');
        $this->assertNotNull($row);
        $this->assertSame($tokenHash, $row['token_hash']);

        // Clean up
        $db->table('email_verifications')->where('email', $email)->delete();
    }

    public function testDebarredSuppliersTableCanInsertAndRetrieve(): void
    {
        $db = \Config\Database::connect('default');
        $identifier = 'PV-99999-TEST';
        
        $inserted = $db->table('debarred_suppliers')->insert([
            'identifier'  => $identifier,
            'name'        => 'Debarred Contractor Ltd',
            'status'      => 'active',
            'reason'      => 'Procurement guideline breach',
            'starts_at'   => date('Y-m-d'),
            'ends_at'     => date('Y-m-d', strtotime('+1 year')),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->assertTrue($inserted);
        $row = $db->table('debarred_suppliers')->where('identifier', $identifier)->get()->getFirstRow('array');
        $this->assertNotNull($row);
        $this->assertSame('active', $row['status']);

        // Clean up
        $db->table('debarred_suppliers')->where('identifier', $identifier)->delete();
    }
}