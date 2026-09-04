<?php

namespace Tests\Unit;

use App\Controllers\Api\V1\Auth\AuthController;
use App\Controllers\Api\V1\Auth\PasswordResetController;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Validates Gate 3C: Password reset lifecycle, anti-enumeration oracle,
 * token single-use, 1-hour expiry, password policy enforcement, and
 * active session / refresh token invalidation.
 */
class PasswordResetTest extends CIUnitTestCase
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

    private function registerUser(string $email, string $password = 'OriginalPass123'): array
    {
        $controller = new AuthController();
        $request = $this->createRequest('/api/v1/auth/register', [
            'name'     => 'Reset Test User',
            'email'    => $email,
            'password' => $password,
            'org_name' => 'Reset Test Org',
        ]);
        $controller->initController($request, Services::response(), new \Psr\Log\NullLogger());
        $resp = $controller->register();
        return json_decode($resp->getBody(), true);
    }

    public function testForgotPasswordAntiEnumerationOracle(): void
    {
        $existingEmail = 'pwd_user_' . bin2hex(random_bytes(4)) . '@test.lk';
        $nonExistentEmail = 'pwd_ghost_' . bin2hex(random_bytes(4)) . '@test.lk';

        $this->registerUser($existingEmail);

        $controller = new PasswordResetController();

        // 1. Request for existing user
        $req1 = $this->createRequest('/api/v1/auth/forgot-password', ['email' => $existingEmail]);
        $controller->initController($req1, Services::response(), new \Psr\Log\NullLogger());
        $resp1 = $controller->forgotPassword();

        // 2. Request for non-existent user
        $req2 = $this->createRequest('/api/v1/auth/forgot-password', ['email' => $nonExistentEmail]);
        $controller->initController($req2, Services::response(), new \Psr\Log\NullLogger());
        $resp2 = $controller->forgotPassword();

        $this->assertSame(200, $resp1->getStatusCode());
        $this->assertSame(200, $resp2->getStatusCode());

        $body1 = json_decode($resp1->getBody(), true);
        $body2 = json_decode($resp2->getBody(), true);

        // Both messages must be identical to avoid account enumeration
        $this->assertSame($body1['data']['message'], $body2['data']['message']);

        // Clean up
        $this->db->table('users')->where('email', $existingEmail)->delete();
        $this->db->table('password_resets')->where('email', $existingEmail)->delete();
    }

    public function testForgotPasswordInsertsHashedTokenWith1HourExpiry(): void
    {
        $email = 'pwd_hash_' . bin2hex(random_bytes(4)) . '@test.lk';
        $this->registerUser($email);

        $controller = new PasswordResetController();
        $req = $this->createRequest('/api/v1/auth/forgot-password', ['email' => $email]);
        $controller->initController($req, Services::response(), new \Psr\Log\NullLogger());
        $resp = $controller->forgotPassword();

        $this->assertSame(200, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $rawToken = $body['meta']['dev_reset_token'] ?? null;
        $this->assertNotEmpty($rawToken, 'Dev reset token must be returned in non-production mode.');

        // Verify token_hash in DB matches sha256(rawToken)
        $expectedHash = hash('sha256', $rawToken);
        $row = $this->db->table('password_resets')->where('email', $email)->get()->getFirstRow('array');
        $this->assertNotNull($row, 'password_resets row must exist.');
        $this->assertSame($expectedHash, $row['token_hash']);

        // Verify raw token is NOT in database
        $this->assertNotSame($rawToken, $row['token_hash']);

        // Verify expiry is ~1 hour (3600 seconds)
        $expiry = strtotime($row['expires_at']);
        $expectedMin = time() + 3550;
        $expectedMax = time() + 3650;
        $this->assertGreaterThanOrEqual($expectedMin, $expiry);
        $this->assertLessThanOrEqual($expectedMax, $expiry);

        // Clean up
        $this->db->table('users')->where('email', $email)->delete();
        $this->db->table('password_resets')->where('email', $email)->delete();
    }

    public function testValidResetUpdatesPasswordAndEnablesNewLogin(): void
    {
        $email = 'pwd_reset_' . bin2hex(random_bytes(4)) . '@test.lk';
        $oldPass = 'OldPassWord123';
        $newPass = 'NewBrandPass999';

        $this->registerUser($email, $oldPass);

        // Forgot password
        $controller = new PasswordResetController();
        $req1 = $this->createRequest('/api/v1/auth/forgot-password', ['email' => $email]);
        $controller->initController($req1, Services::response(), new \Psr\Log\NullLogger());
        $resp1 = $controller->forgotPassword();
        $rawToken = json_decode($resp1->getBody(), true)['meta']['dev_reset_token'];

        // Perform reset with valid token
        $req2 = $this->createRequest('/api/v1/auth/reset-password', [
            'token'    => $rawToken,
            'password' => $newPass,
        ]);
        $controller->initController($req2, Services::response(), new \Psr\Log\NullLogger());
        $resp2 = $controller->resetPassword();

        $this->assertSame(200, $resp2->getStatusCode());

        // Attempt login with OLD password -> must fail (401)
        $auth = new AuthController();
        $loginOldReq = $this->createRequest('/api/v1/auth/login', [
            'email'    => $email,
            'password' => $oldPass,
        ]);
        $auth->initController($loginOldReq, Services::response(), new \Psr\Log\NullLogger());
        $loginOldResp = $auth->login();
        $this->assertSame(401, $loginOldResp->getStatusCode(), 'Old password must be invalidated.');

        // Attempt login with NEW password -> must succeed (200)
        $loginNewReq = $this->createRequest('/api/v1/auth/login', [
            'email'    => $email,
            'password' => $newPass,
        ]);
        $auth->initController($loginNewReq, Services::response(), new \Psr\Log\NullLogger());
        $loginNewResp = $auth->login();
        $this->assertSame(200, $loginNewResp->getStatusCode(), 'New password must authenticate successfully.');

        // Clean up
        $this->db->table('users')->where('email', $email)->delete();
    }

    public function testReplayResetTokenIsRejectedWith400(): void
    {
        $email = 'pwd_replay_' . bin2hex(random_bytes(4)) . '@test.lk';
        $this->registerUser($email);

        $controller = new PasswordResetController();
        $req1 = $this->createRequest('/api/v1/auth/forgot-password', ['email' => $email]);
        $controller->initController($req1, Services::response(), new \Psr\Log\NullLogger());
        $resp1 = $controller->forgotPassword();
        $rawToken = json_decode($resp1->getBody(), true)['meta']['dev_reset_token'];

        // First reset succeeds
        $req2 = $this->createRequest('/api/v1/auth/reset-password', [
            'token'    => $rawToken,
            'password' => 'FirstReset123',
        ]);
        $controller->initController($req2, Services::response(), new \Psr\Log\NullLogger());
        $resp2 = $controller->resetPassword();
        $this->assertSame(200, $resp2->getStatusCode());

        // Second reset attempt with same token (replay attack) must fail
        $req3 = $this->createRequest('/api/v1/auth/reset-password', [
            'token'    => $rawToken,
            'password' => 'SecondReset123',
        ]);
        $controller->initController($req3, Services::response(), new \Psr\Log\NullLogger());
        $resp3 = $controller->resetPassword();
        $this->assertSame(400, $resp3->getStatusCode());
        $body3 = json_decode($resp3->getBody(), true);
        $this->assertSame('invalid_or_expired_token', $body3['reason']);

        // Clean up
        $this->db->table('users')->where('email', $email)->delete();
    }

    public function testExpiredResetTokenIsRejectedWith400(): void
    {
        $email = 'pwd_expired_' . bin2hex(random_bytes(4)) . '@test.lk';
        $this->registerUser($email);

        $controller = new PasswordResetController();
        $req1 = $this->createRequest('/api/v1/auth/forgot-password', ['email' => $email]);
        $controller->initController($req1, Services::response(), new \Psr\Log\NullLogger());
        $resp1 = $controller->forgotPassword();
        $rawToken = json_decode($resp1->getBody(), true)['meta']['dev_reset_token'];

        // Manually backdate expiry in DB
        $this->db->table('password_resets')
            ->where('email', $email)
            ->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);

        $req2 = $this->createRequest('/api/v1/auth/reset-password', [
            'token'    => $rawToken,
            'password' => 'ExpiredResetPass123',
        ]);
        $controller->initController($req2, Services::response(), new \Psr\Log\NullLogger());
        $resp2 = $controller->resetPassword();

        $this->assertSame(400, $resp2->getStatusCode());
        $body2 = json_decode($resp2->getBody(), true);
        $this->assertSame('invalid_or_expired_token', $body2['reason']);

        // Clean up
        $this->db->table('users')->where('email', $email)->delete();
        $this->db->table('password_resets')->where('email', $email)->delete();
    }

    public function testResetPasswordEnforcesComplexityPolicy(): void
    {
        $email = 'pwd_policy_' . bin2hex(random_bytes(4)) . '@test.lk';
        $this->registerUser($email);

        $controller = new PasswordResetController();
        $req1 = $this->createRequest('/api/v1/auth/forgot-password', ['email' => $email]);
        $controller->initController($req1, Services::response(), new \Psr\Log\NullLogger());
        $resp1 = $controller->forgotPassword();
        $rawToken = json_decode($resp1->getBody(), true)['meta']['dev_reset_token'];

        // Reset with weak password (letters only)
        $req2 = $this->createRequest('/api/v1/auth/reset-password', [
            'token'    => $rawToken,
            'password' => 'onlylettersnondigits',
        ]);
        $controller->initController($req2, Services::response(), new \Psr\Log\NullLogger());
        $resp2 = $controller->resetPassword();

        $this->assertSame(422, $resp2->getStatusCode());
        $body2 = json_decode($resp2->getBody(), true);
        $this->assertSame('weak_password', $body2['reason']);

        // Clean up
        $this->db->table('users')->where('email', $email)->delete();
        $this->db->table('password_resets')->where('email', $email)->delete();
    }

    public function testResetPasswordRevokesAllActiveRefreshTokens(): void
    {
        $email = 'pwd_sessions_' . bin2hex(random_bytes(4)) . '@test.lk';
        $reg = $this->registerUser($email);
        $userId = $reg['data']['user']['id'];

        // User logs in twice to create two refresh token sessions
        $auth = new AuthController();
        $loginReq1 = $this->createRequest('/api/v1/auth/login', [
            'email'    => $email,
            'password' => 'OriginalPass123',
        ]);
        $auth->initController($loginReq1, Services::response(), new \Psr\Log\NullLogger());
        $auth->login();

        $loginReq2 = $this->createRequest('/api/v1/auth/login', [
            'email'    => $email,
            'password' => 'OriginalPass123',
        ]);
        $auth->initController($loginReq2, Services::response(), new \Psr\Log\NullLogger());
        $auth->login();

        // Check that refresh tokens exist
        $activeTokensBefore = $this->db->table('refresh_tokens')->where('user_id', $userId)->countAllResults();
        $this->assertGreaterThanOrEqual(2, $activeTokensBefore);

        // Forgot & Reset password
        $controller = new PasswordResetController();
        $req1 = $this->createRequest('/api/v1/auth/forgot-password', ['email' => $email]);
        $controller->initController($req1, Services::response(), new \Psr\Log\NullLogger());
        $resp1 = $controller->forgotPassword();
        $rawToken = json_decode($resp1->getBody(), true)['meta']['dev_reset_token'];

        $req2 = $this->createRequest('/api/v1/auth/reset-password', [
            'token'    => $rawToken,
            'password' => 'FreshNewPass123',
        ]);
        $controller->initController($req2, Services::response(), new \Psr\Log\NullLogger());
        $resp2 = $controller->resetPassword();
        $this->assertSame(200, $resp2->getStatusCode());

        // All active refresh tokens for this user must be purged
        $activeTokensAfter = $this->db->table('refresh_tokens')->where('user_id', $userId)->countAllResults();
        $this->assertSame(0, $activeTokensAfter, 'All active refresh token sessions must be invalidated upon password reset.');

        // Clean up
        $this->db->table('users')->where('email', $email)->delete();
    }
}
