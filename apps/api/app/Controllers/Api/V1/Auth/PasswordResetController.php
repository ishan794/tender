<?php

namespace App\Controllers\Api\V1\Auth;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Validation\IdentityValidator;

class PasswordResetController extends BaseApiController
{
    /**
     * POST /api/v1/auth/forgot-password
     * Generates a single-use cryptographically secure reset token with 1-hour expiry.
     */
    public function forgotPassword()
    {
        $in = $this->body();

        if (isset($in['email'])) {
            $in['email'] = IdentityValidator::normalizeEmail($in['email']);
        }

        $rules = [
            'email' => 'required|valid_email',
        ];

        if (! $this->validateData($in, $rules)) {
            return problem(422, 'validation_failed', 'A valid email is required.', ['errors' => $this->validator->getErrors()]);
        }

        $email = $in['email'];
        $users = model('App\Models\UserModel');
        $user  = $users->where('email', $email)->first();

        // Always return generic success to prevent email enumeration / user discovery attacks
        if (! $user) {
            return $this->ok([
                'message' => 'If an account exists with that email, a password reset link has been dispatched.',
            ]);
        }

        // Generate high-entropy 64-char token
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour

        $db = db_connect('default');
        
        // Invalidate any previous unused tokens for this user
        $db->table('password_resets')->where('email', $email)->delete();

        // Insert new reset record
        $db->table('password_resets')->insert([
            'email'      => $email,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // In development/test mode, return reset_token in response meta for automation
        $meta = [];
        if (ENVIRONMENT !== 'production') {
            $meta['dev_reset_token'] = $rawToken;
            $meta['dev_reset_url']   = "https://tenderhub.lk/auth/reset-password?token=" . $rawToken;
        }

        return $this->ok([
            'message' => 'If an account exists with that email, a password reset link has been dispatched.',
        ], $meta);
    }

    /**
     * POST /api/v1/auth/reset-password
     * Verifies the token, updates the password hash, and purges active sessions.
     */
    public function resetPassword()
    {
        $in = $this->body();
        $rules = [
            'token'    => 'required|min_length[32]',
            'password' => 'required|min_length[8]',
        ];

        if (! $this->validateData($in, $rules)) {
            return problem(422, 'validation_failed', 'Valid token and new password (min 8 chars) required.', ['errors' => $this->validator->getErrors()]);
        }

        // Password complexity check
        $pwdCheck = IdentityValidator::validatePassword($in['password'] ?? '');
        if (! $pwdCheck['valid']) {
            return problem(422, 'weak_password', $pwdCheck['error']);
        }

        $rawToken  = trim($in['token']);
        $tokenHash = hash('sha256', $rawToken);

        $db = db_connect('default');
        $record = $db->table('password_resets')
            ->where('token_hash', $tokenHash)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->get()
            ->getFirstRow('array');

        if (! $record) {
            return problem(400, 'invalid_or_expired_token', 'The password reset token is invalid or has expired. Please request a new link.');
        }

        $users = model('App\Models\UserModel');
        $user  = $users->where('email', $record['email'])->first();

        if (! $user) {
            return problem(400, 'invalid_or_expired_token', 'The password reset token is invalid or has expired.');
        }

        // Update password hash and revoke token within safe transaction
        $db->transBegin();
        try {
            $users->update($user['id'], [
                'password_hash' => password_hash($in['password'], PASSWORD_DEFAULT),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

            // Delete used token to prevent replay attacks
            $db->table('password_resets')->where('email', $record['email'])->delete();

            // Invalidate all active refresh token families for security
            $db->table('refresh_tokens')->where('user_id', $user['id'])->delete();

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            return problem(500, 'reset_failed', 'Could not complete password reset. Please try again.');
        }

        return $this->ok([
            'message' => 'Password has been successfully reset. You may now sign in with your new credentials.',
        ]);
    }
}
