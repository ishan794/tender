<?php

namespace Tests\Unit;

use App\Controllers\Api\V1\Auth\AuthController;
use App\Controllers\Api\V1\Auth\EmailVerificationController;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Validates Gate 3B: Email verification lifecycle, token hashing,
 * single-use guarantee, expiration enforcement, and anti-enumeration oracle.
 */
class EmailVerificationTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect('default');
    }

    private function createRequest(string $uriPath, array $body): IncomingRequest
    {
        $uri = new URI('http://localhost:8080' . $uriPath);
        $request = new IncomingRequest(new App(), $uri, 'php://input', new UserAgent());
        $request->setMethod('POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setBody(json_encode($body));
        return $request;
    }

    private function registerUser(string $email, string $password = 'SecurePass123'): array
    {
        $controller = new AuthController();
        $request = $this->createRequest('/api/v1/auth/register', [
            'name'     => 'Verification Test User',
            'email'    => $email,
            'password' => $password,
            'org_name' => 'Verify Test Ltd',
        ]);
        $controller->initController($request, Services::response(), new \Psr\Log\NullLogger());
        $resp = $controller->register();
        $data = json_decode($resp->getBody(), true);
        return [
            'status' => $resp->getStatusCode(),
            'body'   => $data,
            'token'  => $data['meta']['dev_verification_token'] ?? null,
        ];
    }

    public function testRegistrationCreatesHashedTokenWith24HourExpiry(): void
    {
        $email = 'verif_hash_' . bin2hex(random_bytes(4)) . '@test.lk';
        $res = $this->registerUser($email);
        $this->assertSame(201, $res['status']);
        $rawToken = $res['token'];
        $this->assertNotEmpty($rawToken, 'Dev verification token must be returned in non-production mode.');

        // Verify token_hash in DB matches sha256(rawToken)
        $expectedHash = hash('sha256', $rawToken);
        $row = $this->db->table('email_verifications')->where('email', $email)->get()->getFirstRow('array');
        $this->assertNotNull($row, 'email_verifications record must exist.');
        $this->assertSame($expectedHash, $row['token_hash']);

        // Verify raw token is NEVER stored in database
        $this->assertNotSame($rawToken, $row['token_hash']);

        // Verify expiry is approximately 24 hours in the future
        $expiryTime = strtotime($row['expires_at']);
        $expectedMin = time() + 86000;
        $expectedMax = time() + 86500;
        $this->assertGreaterThanOrEqual($expectedMin, $expiryTime);
        $this->assertLessThanOrEqual($expectedMax, $expiryTime);

        // Clean up
        $this->db->table('users')->where('email', $email)->delete();
        $this->db->table('email_verifications')->where('email', $email)->delete();
    }

    public function testValidTokenVerificationActivatesUserAndDeletesToken(): void
    {
        $email = 'verif_success_' . bin2hex(random_bytes(4)) . '@test.lk';
        $res = $this->registerUser($email);
        $rawToken = $res['token'];

        // Initially email_verified_at should be null
        $userBefore = $this->db->table('users')->where('email', $email)->get()->getFirstRow('array');
        $this->assertNull($userBefore['email_verified_at']);

        // Verify with valid token
        $controller = new EmailVerificationController();
        $req = $this->createRequest('/api/v1/auth/verify-email', ['token' => $rawToken]);
        $controller->initController($req, Services::response(), new \Psr\Log\NullLogger());
        $resp = $controller->verify();

        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $this->assertTrue($body['data']['verified']);

        // Verify user row has email_verified_at populated
        $userAfter = $this->db->table('users')->where('email', $email)->get()->getFirstRow('array');
        $this->assertNotNull($userAfter['email_verified_at']);
        $this->assertSame('active', $userAfter['status']);

        // Token must be purged from email_verifications table (single-use guarantee)
        $tokenRow = $this->db->table('email_verifications')->where('email', $email)->get()->getFirstRow('array');
        $this->assertNull($tokenRow, 'Token must be deleted after consumption.');

        // Clean up
        $this->db->table('users')->where('email', $email)->delete();
    }

    public function testReplayTokenIsRejectedWith400(): void
    {
        $email = 'verif_replay_' . bin2hex(random_bytes(4)) . '@test.lk';
        $res = $this->registerUser($email);
        $rawToken = $res['token'];

        $controller = new EmailVerificationController();

        // First verification succeeds
        $req1 = $this->createRequest('/api/v1/auth/verify-email', ['token' => $rawToken]);
        $controller->initController($req1, Services::response(), new \Psr\Log\NullLogger());
        $resp1 = $controller->verify();
        $this->assertSame(200, $resp1->getStatusCode());

        // Second verification attempt (replay attack) must fail with 400
        $req2 = $this->createRequest('/api/v1/auth/verify-email', ['token' => $rawToken]);
        $controller->initController($req2, Services::response(), new \Psr\Log\NullLogger());
        $resp2 = $controller->verify();
        $this->assertSame(400, $resp2->getStatusCode());
        $body2 = json_decode($resp2->getBody(), true);
        $this->assertSame('invalid_or_expired_token', $body2['reason']);

        // Clean up
        $this->db->table('users')->where('email', $email)->delete();
    }

    public function testExpiredTokenIsRejectedWith400(): void
    {
        $email = 'verif_expired_' . bin2hex(random_bytes(4)) . '@test.lk';
        $res = $this->registerUser($email);
        $rawToken = $res['token'];

        // Manually backdate token in DB to simulate expired token (>24h ago)
        $this->db->table('email_verifications')
            ->where('email', $email)
            ->update(['expires_at' => date('Y-m-d H:i:s', time() - 3600)]);

        $controller = new EmailVerificationController();
        $req = $this->createRequest('/api/v1/auth/verify-email', ['token' => $rawToken]);
        $controller->initController($req, Services::response(), new \Psr\Log\NullLogger());
        $resp = $controller->verify();

        $this->assertSame(400, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $this->assertSame('invalid_or_expired_token', $body['reason']);

        // User must remain unverified
        $user = $this->db->table('users')->where('email', $email)->get()->getFirstRow('array');
        $this->assertNull($user['email_verified_at']);

        // Clean up
        $this->db->table('users')->where('email', $email)->delete();
        $this->db->table('email_verifications')->where('email', $email)->delete();
    }

    public function testTamperedTokenIsRejectedWith400(): void
    {
        $email = 'verif_tamper_' . bin2hex(random_bytes(4)) . '@test.lk';
        $this->registerUser($email);

        $controller = new EmailVerificationController();
        $req = $this->createRequest('/api/v1/auth/verify-email', ['token' => 'a' . bin2hex(random_bytes(24))]);
        $controller->initController($req, Services::response(), new \Psr\Log\NullLogger());
        $resp = $controller->verify();

        $this->assertSame(400, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $this->assertSame('invalid_or_expired_token', $body['reason']);

        // Clean up
        $this->db->table('users')->where('email', $email)->delete();
        $this->db->table('email_verifications')->where('email', $email)->delete();
    }

    public function testResendVerificationInvalidatesPriorTokens(): void
    {
        $email = 'verif_resend_' . bin2hex(random_bytes(4)) . '@test.lk';
        $res1 = $this->registerUser($email);
        $oldToken = $res1['token'];

        $controller = new EmailVerificationController();
        $req = $this->createRequest('/api/v1/auth/resend-verification', ['email' => $email]);
        $controller->initController($req, Services::response(), new \Psr\Log\NullLogger());
        $resp = $controller->resend();

        $this->assertSame(200, $resp->getStatusCode());
        $res2Data = json_decode($resp->getBody(), true);
        $newToken = $res2Data['meta']['dev_verification_token'] ?? null;
        $this->assertNotEmpty($newToken);
        $this->assertNotSame($oldToken, $newToken, 'Resend must issue a distinct new token.');

        // Old token must fail
        $reqOld = $this->createRequest('/api/v1/auth/verify-email', ['token' => $oldToken]);
        $controller->initController($reqOld, Services::response(), new \Psr\Log\NullLogger());
        $respOld = $controller->verify();
        $this->assertSame(400, $respOld->getStatusCode());

        // New token must succeed
        $reqNew = $this->createRequest('/api/v1/auth/verify-email', ['token' => $newToken]);
        $controller->initController($reqNew, Services::response(), new \Psr\Log\NullLogger());
        $respNew = $controller->verify();
        $this->assertSame(200, $respNew->getStatusCode());

        // Clean up
        $this->db->table('users')->where('email', $email)->delete();
    }

    public function testResendRejectsAlreadyVerifiedUserWith400(): void
    {
        $email = 'verif_already_' . bin2hex(random_bytes(4)) . '@test.lk';
        $res = $this->registerUser($email);

        // Verify first
        $controller = new EmailVerificationController();
        $req1 = $this->createRequest('/api/v1/auth/verify-email', ['token' => $res['token']]);
        $controller->initController($req1, Services::response(), new \Psr\Log\NullLogger());
        $resp1 = $controller->verify();
        $this->assertSame(200, $resp1->getStatusCode());

        // Try to resend for already verified account
        $req2 = $this->createRequest('/api/v1/auth/resend-verification', ['email' => $email]);
        $controller->initController($req2, Services::response(), new \Psr\Log\NullLogger());
        $resp2 = $controller->resend();
        $this->assertSame(400, $resp2->getStatusCode());
        $body = json_decode($resp2->getBody(), true);
        $this->assertSame('already_verified', $body['reason']);

        // Clean up
        $this->db->table('users')->where('email', $email)->delete();
    }

    public function testResendForNonExistentUserReturnsGenericSuccess(): void
    {
        $email = 'nonexistent_' . bin2hex(random_bytes(4)) . '@test.lk';

        $controller = new EmailVerificationController();
        $req = $this->createRequest('/api/v1/auth/resend-verification', ['email' => $email]);
        $controller->initController($req, Services::response(), new \Psr\Log\NullLogger());
        $resp = $controller->resend();

        // Must return 200 generic message to prevent account enumeration
        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $this->assertStringContainsString('If an account exists', $body['data']['message']);
    }
}
