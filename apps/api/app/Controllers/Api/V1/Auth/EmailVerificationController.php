<?php

namespace App\Controllers\Api\V1\Auth;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Validation\IdentityValidator;

class EmailVerificationController extends BaseApiController
{
    /**
     * POST /api/v1/auth/verify-email
     * Verifies user email via confirmation token.
     */
    public function verify()
    {
        $in = $this->body();
        $rules = [
            'token' => 'required|min_length[16]',
        ];

        if (! $this->validateData($in, $rules)) {
            return problem(422, 'validation_failed', 'A verification token is required.', ['errors' => $this->validator->getErrors()]);
        }

        $rawToken  = trim($in['token']);
        $tokenHash = hash('sha256', $rawToken);

        $db = db_connect('default');
        $record = $db->table('email_verifications')
            ->where('token_hash', $tokenHash)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->get()
            ->getFirstRow('array');

        if (! $record) {
            return problem(400, 'invalid_or_expired_token', 'Verification token is invalid or has expired.');
        }

        $users = model('App\Models\UserModel');
        $user  = $users->where('email', $record['email'])->first();

        if ($user) {
            $users->update($user['id'], [
                'email_verified_at' => date('Y-m-d H:i:s'),
                'status'            => 'active',
            ]);
        }

        // Clean up token (single-use guarantee)
        $db->table('email_verifications')->where('email', $record['email'])->delete();

        return $this->ok([
            'verified' => true,
            'message'  => 'Your email address has been verified successfully.',
        ]);
    }

    /**
     * POST /api/v1/auth/resend-verification
     * Generates a new verification token and dispatches verification email.
     */
    public function resend()
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

        if (! $user) {
            return $this->ok([
                'message' => 'If an account exists with that email, a verification link has been sent.',
            ]);
        }

        if (! empty($user['email_verified_at'])) {
            return problem(400, 'already_verified', 'This email address is already verified.');
        }

        $rawToken  = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24 hours

        $db = db_connect('default');
        $db->table('email_verifications')->where('email', $email)->delete();
        $db->table('email_verifications')->insert([
            'email'      => $email,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $meta = [];
        if (ENVIRONMENT !== 'production') {
            $meta['dev_verification_token'] = $rawToken;
            $meta['dev_verification_url']   = "https://tenderhub.lk/auth/verify?token=" . $rawToken;
        }

        return $this->ok([
            'message' => 'If an account exists with that email, a verification link has been sent.',
        ], $meta);
    }
}
