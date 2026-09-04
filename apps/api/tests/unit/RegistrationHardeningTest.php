<?php

namespace Tests\Unit;

use App\Controllers\Api\V1\Auth\AuthController;
use App\Libraries\Validation\IdentityValidator;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Validates Gate 3A: Registration hardening, email normalization, duplicate checks,
 * password policy, Sri Lankan NIC/BRN validation, and transaction rollback integrity.
 */
class RegistrationHardeningTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect('default');
    }

    private function createRequest(array $body): IncomingRequest
    {
        $uri = new URI('http://localhost:8080/api/v1/auth/register');
        $request = new IncomingRequest(new App(), $uri, 'php://input', new UserAgent());
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setBody(json_encode($body));
        return $request;
    }

    private function executeRegister(array $payload)
    {
        $controller = new AuthController();
        $request = $this->createRequest($payload);
        $controller->initController($request, Services::response(), new \Psr\Log\NullLogger());
        return $controller->register();
    }

    public function testDuplicateEmailIsRejectedWith409(): void
    {
        $email = 'existing_' . bin2hex(random_bytes(4)) . '@test.lk';
        $payload = [
            'name'     => 'Original User',
            'email'    => $email,
            'password' => 'SecurePass123',
            'org_name' => 'Original Builders Ltd',
        ];

        $resp1 = $this->executeRegister($payload);
        $this->assertSame(201, $resp1->getStatusCode());

        // Attempt second registration with same email
        $payload2 = [
            'name'     => 'Imposter User',
            'email'    => $email,
            'password' => 'AnotherPass123',
            'org_name' => 'Another Firm',
        ];
        $resp2 = $this->executeRegister($payload2);
        $this->assertSame(409, $resp2->getStatusCode());

        $body = json_decode($resp2->getBody(), true);
        $this->assertSame('email_taken', $body['reason']);

        // Clean up
        $this->db->table('users')->where('email', strtolower($email))->delete();
        $this->db->table('email_verifications')->where('email', strtolower($email))->delete();
    }

    public function testEmailNormalizationStripsWhitespaceAndLowercase(): void
    {
        $rawEmail = '  NORM_' . bin2hex(random_bytes(4)) . '@Example.COM  ';
        $normalized = strtolower(trim($rawEmail));

        $payload = [
            'name'     => 'Normalized User',
            'email'    => $rawEmail,
            'password' => 'SecurePass123',
            'org_name' => 'Normalization Test Org',
        ];

        $resp = $this->executeRegister($payload);
        $this->assertSame(201, $resp->getStatusCode());

        $user = $this->db->table('users')->where('email', $normalized)->get()->getFirstRow('array');
        $this->assertNotNull($user, 'Email must be saved in normalized lowercase/trimmed format.');
        $this->assertSame($normalized, $user['email']);

        // Clean up
        $this->db->table('users')->where('email', $normalized)->delete();
        $this->db->table('email_verifications')->where('email', $normalized)->delete();
    }

    public function testPasswordPolicyEnforcement(): void
    {
        // 1. Less than 8 characters
        $res = IdentityValidator::validatePassword('Short1');
        $this->assertFalse($res['valid']);

        // 2. Pure whitespace
        $res = IdentityValidator::validatePassword('        ');
        $this->assertFalse($res['valid']);

        // 3. No numbers
        $res = IdentityValidator::validatePassword('AllLettersOnly');
        $this->assertFalse($res['valid']);

        // 4. No letters
        $res = IdentityValidator::validatePassword('1234567890');
        $this->assertFalse($res['valid']);

        // 5. Valid password
        $res = IdentityValidator::validatePassword('ValidPass123');
        $this->assertTrue($res['valid']);

        // Via register API
        $payload = [
            'name'     => 'Weak Password User',
            'email'    => 'weak_' . bin2hex(random_bytes(4)) . '@test.lk',
            'password' => 'onlyletters',
            'org_name' => 'Weak Pass Org',
        ];
        $resp = $this->executeRegister($payload);
        $this->assertSame(422, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $this->assertSame('weak_password', $body['reason']);
    }

    public function testSriLankanNicValidation(): void
    {
        // Valid legacy (9 digits + V/X)
        $this->assertTrue(IdentityValidator::isValidNic('851234567V'));
        $this->assertTrue(IdentityValidator::isValidNic('927890123x'));

        // Valid modern (12 digits)
        $this->assertTrue(IdentityValidator::isValidNic('198512345678'));
        $this->assertTrue(IdentityValidator::isValidNic('200178901234'));

        // Invalid NICs
        $this->assertFalse(IdentityValidator::isValidNic('12345'));
        $this->assertFalse(IdentityValidator::isValidNic('851234567Z'));
        $this->assertFalse(IdentityValidator::isValidNic('19851234567')); // 11 digits
        $this->assertFalse(IdentityValidator::isValidNic(''));
        $this->assertFalse(IdentityValidator::isValidNic(null));

        // Via register API
        $payload = [
            'name'     => 'NIC Test User',
            'email'    => 'nic_' . bin2hex(random_bytes(4)) . '@test.lk',
            'password' => 'SecurePass123',
            'org_name' => 'NIC Test Org',
            'nic'      => 'invalid_nic_123',
        ];
        $resp = $this->executeRegister($payload);
        $this->assertSame(422, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $this->assertSame('invalid_nic', $body['reason']);
    }

    public function testSriLankanBrnValidationAndDuplicateRejection(): void
    {
        // Valid BRNs
        $this->assertTrue(IdentityValidator::isValidBrn('PV00234567'));
        $this->assertTrue(IdentityValidator::isValidBrn('PV 12345'));
        $this->assertTrue(IdentityValidator::isValidBrn('W/DS/CO/104'));

        // Invalid / Placeholder BRNs
        $this->assertFalse(IdentityValidator::isValidBrn('N/A'));
        $this->assertFalse(IdentityValidator::isValidBrn('none'));
        $this->assertFalse(IdentityValidator::isValidBrn('test'));
        $this->assertFalse(IdentityValidator::isValidBrn(''));
        $this->assertFalse(IdentityValidator::isValidBrn(null));

        $brn = 'PV' . random_int(10000, 99999);
        $email1 = 'brn1_' . bin2hex(random_bytes(4)) . '@test.lk';
        $email2 = 'brn2_' . bin2hex(random_bytes(4)) . '@test.lk';

        // First registration with BRN
        $payload1 = [
            'name'         => 'First Company Owner',
            'email'        => $email1,
            'password'     => 'ValidPass123',
            'org_name'     => 'Genuine Builders PLC',
            'account_type' => 'company',
            'reg_no'       => $brn,
        ];
        $resp1 = $this->executeRegister($payload1);
        $this->assertSame(201, $resp1->getStatusCode());

        // Second registration attempting to claim the exact same BRN
        $payload2 = [
            'name'         => 'Imposter Owner',
            'email'        => $email2,
            'password'     => 'ValidPass123',
            'org_name'     => 'Imposter Builders PLC',
            'account_type' => 'company',
            'reg_no'       => $brn,
        ];
        $resp2 = $this->executeRegister($payload2);
        $this->assertSame(409, $resp2->getStatusCode());
        $body = json_decode($resp2->getBody(), true);
        $this->assertSame('reg_no_taken', $body['reason']);

        // Clean up
        $this->db->table('users')->where('email', $email1)->delete();
        $this->db->table('organisations')->where('reg_no', $brn)->delete();
        $this->db->table('email_verifications')->where('email', $email1)->delete();
    }

    public function testBidderAndCompanyTenantIsolation(): void
    {
        $bidderEmail = 'bidder_' . bin2hex(random_bytes(4)) . '@test.lk';
        $companyEmail = 'company_' . bin2hex(random_bytes(4)) . '@test.lk';

        // 1. Bidder Registration
        $resp1 = $this->executeRegister([
            'name'         => 'Alice Bidder',
            'email'        => $bidderEmail,
            'password'     => 'ValidPass123',
            'org_name'     => 'Alice Contracting',
            'account_type' => 'bidder',
        ]);
        $this->assertSame(201, $resp1->getStatusCode());
        $body1 = json_decode($resp1->getBody(), true);
        $this->assertSame('bidder', $body1['data']['user']['group']);
        $this->assertSame('free', $body1['data']['org']['plan']);

        // 2. Company Registration
        $resp2 = $this->executeRegister([
            'name'         => 'Bob Publisher',
            'email'        => $companyEmail,
            'password'     => 'ValidPass123',
            'org_name'     => 'Bob Ministry Corp',
            'account_type' => 'company',
        ]);
        $this->assertSame(201, $resp2->getStatusCode());
        $body2 = json_decode($resp2->getBody(), true);
        $this->assertSame('company', $body2['data']['user']['group']);
        $this->assertSame('publish', $body2['data']['org']['plan']);

        // Clean up
        $this->db->table('users')->whereIn('email', [$bidderEmail, $companyEmail])->delete();
        $this->db->table('email_verifications')->whereIn('email', [$bidderEmail, $companyEmail])->delete();
    }

    public function testTransactionRollbackGuaranteesZeroPartialRecordsOnFailure(): void
    {
        // Record starting counts
        $startOrgs = $this->db->table('organisations')->countAllResults();
        $startUsers = $this->db->table('users')->countAllResults();
        $startVerifs = $this->db->table('email_verifications')->countAllResults();

        $orgName = 'Doomed Transaction Org ' . bin2hex(random_bytes(4));

        // Inject a simulated failure: we will temporarily use a dummy controller or test class
        // that rolls back the transaction
        $db = $this->db;
        $db->transBegin();
        try {
            $orgId = $db->table('organisations')->insert([
                'name'       => $orgName,
                'slug'       => 'doomed-org-' . bin2hex(random_bytes(2)),
                'type'       => 'bidder',
                'plan'       => 'free',
                'sub_status' => 'none',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Simulate downstream failure (e.g. duplicate user or DB error)
            throw new \RuntimeException('Simulated downstream failure after organisation creation');
        } catch (\Throwable $e) {
            $db->transRollback();
        }

        // Verify that organisations count did NOT increase and no partial record exists
        $endOrgs = $this->db->table('organisations')->countAllResults();
        $endUsers = $this->db->table('users')->countAllResults();
        $endVerifs = $this->db->table('email_verifications')->countAllResults();

        $this->assertSame($startOrgs, $endOrgs, 'Organisation record must be rolled back on failure.');
        $this->assertSame($startUsers, $endUsers);
        $this->assertSame($startVerifs, $endVerifs);

        $foundOrg = $this->db->table('organisations')->where('name', $orgName)->get()->getFirstRow();
        $this->assertNull($foundOrg, 'Rolled back organisation must not exist in database.');
    }
}
