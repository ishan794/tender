<?php

namespace App\Controllers\Api\V1\Auth;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Jwt;

class AuthController extends BaseApiController
{
    private const REFRESH_TTL = 2592000; // 30 days

    public function register()
    {
        $in   = $this->body();
        $kind = ($in['account_type'] ?? 'bidder') === 'company' ? 'company' : 'bidder';

        $rules = [
            'name'     => 'required|min_length[2]',
            'email'    => 'required|valid_email',
            'password' => 'required|min_length[8]',
            'org_name' => 'required|min_length[2]',
        ];
        if (! $this->validateData($in, $rules)) {
            return problem(422, 'validation_failed', 'Check the form.', ['errors' => $this->validator->getErrors()]);
        }

        $users = model('App\Models\UserModel');
        if ($users->where('email', strtolower($in['email']))->first()) {
            return problem(409, 'email_taken', 'That e-mail already has an account.');
        }

        $orgs = model('App\Models\OrganisationModel');
        $db   = db_connect();
        $db->transBegin();

        $slug = url_title($in['org_name'], '-', true) . '-' . bin2hex(random_bytes(2));
        $orgId = $orgs->insert([
            'name' => $in['org_name'], 'slug' => $slug, 'type' => $kind,
            // Companies are free BY DECISION, not by oversight: the publish plan
            // has everything enabled and a price of zero. Switching pricing on
            // is a config change, not a migration.
            'plan' => $kind === 'company' ? 'publish' : 'free',
            'sub_status' => $kind === 'company' ? 'active' : 'none',
            'district_id' => $in['district_id'] ?? null,
            'reg_no' => $in['reg_no'] ?? null,
            'contact_email' => strtolower($in['email']),
        ], true);

        $userId = $users->insert([
            'org_id' => $orgId, 'name' => $in['name'], 'email' => strtolower($in['email']),
            'phone' => $in['phone'] ?? null,
            'password_hash' => password_hash($in['password'], PASSWORD_DEFAULT),
            'role' => 'owner', 'user_group' => $kind, 'status' => 'active',
        ], true);

        $db->transCommit();

        return $this->ok($this->session((int) $userId), [], 201);
    }

    public function login()
    {
        $in = $this->body();

        $user = model('App\Models\UserModel')->where('email', strtolower($in['email'] ?? ''))->first();

        // Identical error for an unknown account and a wrong password. Neither
        // is allowed to become an account-enumeration oracle.
        $deny = static fn () => problem(401, 'invalid_credentials', 'E-mail or password is incorrect.');

        if (! $user || ! $user['password_hash'] || ! password_verify((string) ($in['password'] ?? ''), $user['password_hash'])) {
            \App\Libraries\Monitoring\SecurityMonitor::record('auth_failure', 'warning', $user['id'] ?? null,
                'failed login for ' . substr((string) ($in['email'] ?? ''), 0, 120));
            return $deny();
        }
        if ($user['status'] !== 'active') {
            return problem(403, 'account_suspended', 'This account is not active.');
        }

        return $this->ok($this->session((int) $user['id']));
    }

    /** Identical response whether or not the number exists. */
    public function otpRequest()
    {
        $phone = preg_replace('/\D/', '', (string) ($this->body()['phone'] ?? ''));
        $users = model('App\Models\UserModel');
        $user  = $phone ? $users->like('phone', substr($phone, -9))->first() : null;

        $debug = null;
        if ($user) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $users->update($user['id'], [
                'otp_hash' => password_hash($code, PASSWORD_DEFAULT),
                'otp_expires_at' => date('Y-m-d H:i:s', time() + 300),
            ]);
            // Delivery is not wired (§ 15). In development the code is returned
            // so the flow is testable; this branch is gated on CI_ENVIRONMENT.
            if (ENVIRONMENT === 'development') {
                $debug = $code;
            }
        }

        return $this->ok(['sent' => true] + ($debug ? ['dev_code' => $debug] : []), [
            'note' => 'If that number has an account, a code has been sent.',
        ]);
    }

    public function otpVerify()
    {
        $in    = $this->body();
        $phone = preg_replace('/\D/', '', (string) ($in['phone'] ?? ''));
        $users = model('App\Models\UserModel');
        $user  = $phone ? $users->like('phone', substr($phone, -9))->first() : null;

        if (! $user || ! $user['otp_hash'] || strtotime((string) $user['otp_expires_at']) < time()
            || ! password_verify((string) ($in['code'] ?? ''), $user['otp_hash'])) {
            return problem(401, 'invalid_code', 'That code is not valid.');
        }

        $users->update($user['id'], ['otp_hash' => null, 'otp_expires_at' => null]);

        return $this->ok($this->session((int) $user['id']));
    }

    /**
     * Refresh tokens rotate. Presenting one that has already been used revokes
     * the whole family — that is the signature of a stolen token being replayed
     * alongside the legitimate one.
     */
    public function refresh()
    {
        $given = (string) ($this->body()['refresh_token'] ?? '');
        $hash  = hash('sha256', $given);
        $db    = db_connect();
        $row   = $db->table('refresh_tokens')->where('token_hash', $hash)->get()->getFirstRow('array');

        if (! $row) {
            return problem(401, 'invalid_refresh', 'Sign in again.');
        }

        if ($row['used_at'] !== null || $row['revoked_at'] !== null) {
            $db->table('refresh_tokens')->where('family_id', $row['family_id'])
                ->update(['revoked_at' => date('Y-m-d H:i:s')]);

            return problem(401, 'refresh_reuse', 'Session revoked. Sign in again.');
        }

        if (strtotime($row['expires_at']) < time()) {
            return problem(401, 'invalid_refresh', 'Sign in again.');
        }

        $db->table('refresh_tokens')->where('id', $row['id'])->update(['used_at' => date('Y-m-d H:i:s')]);

        return $this->ok($this->session((int) $row['user_id'], $row['family_id']));
    }

    public function logout()
    {
        $given = (string) ($this->body()['refresh_token'] ?? '');
        if ($given !== '') {
            $db  = db_connect();
            $row = $db->table('refresh_tokens')->where('token_hash', hash('sha256', $given))->get()->getFirstRow('array');
            if ($row) {
                $db->table('refresh_tokens')->where('family_id', $row['family_id'])
                    ->update(['revoked_at' => date('Y-m-d H:i:s')]);
            }
        }

        return $this->ok(['signed_out' => true]);
    }

    private function session(int $userId, ?string $family = null): array
    {
        $user = model('App\Models\UserModel')->find($userId);
        $org  = model('App\Models\OrganisationModel')->find((int) $user['org_id']);

        $access = Jwt::issue([
            'sub'  => (int) $user['id'],
            'org'  => (int) $org['id'],
            'role' => $user['role'],
            'grp'  => $user['user_group'],
            'st'   => $org['sub_status'],
            'plan' => $org['plan'],
            'nm'   => $user['name'],
        ]);

        $refresh = bin2hex(random_bytes(32));
        db_connect()->table('refresh_tokens')->insert([
            'user_id'    => $user['id'],
            'family_id'  => $family ?: bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', $refresh),
            'expires_at' => date('Y-m-d H:i:s', time() + self::REFRESH_TTL),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        model('App\Models\UserModel')->update($userId, ['last_login_at' => date('Y-m-d H:i:s')]);

        return [
            'access_token'  => $access,
            'expires_in'    => Jwt::TTL,
            'refresh_token' => $refresh,
            'user' => [
                'id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'],
                'role' => $user['role'], 'group' => $user['user_group'],
                'free_views_used' => (int) $user['free_views'],
            ],
            'org' => [
                'id' => (int) $org['id'], 'name' => $org['name'], 'type' => $org['type'],
                'plan' => $org['plan'], 'sub_status' => $org['sub_status'],
                'renews_at' => $org['renews_at'], 'verify_state' => $org['verify_state'],
            ],
        ];
    }
}
