<?php

namespace App\Commands;

use App\Libraries\Validation\IdentityValidator;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * AdminProvision Command
 *
 * Safely provisions or updates a TenderHub staff administrator account.
 * Follows zero-leakage security rules:
 * - Uses password_hash($pwd, PASSWORD_DEFAULT)
 * - Enforces minimum password complexity requirements
 * - Attaches user to a verified staff organisation with plan='staff'
 * - Sets email_verified_at and status='active'
 * - Can be executed interactively or non-interactively via CLI options / environment variables
 */
class AdminProvision extends BaseCommand
{
    protected $group       = 'TenderHub';
    protected $name        = 'admin:provision';
    protected $description = 'Safely bootstrap or update a TenderHub staff administrator account.';
    protected $usage       = 'admin:provision [options]';
    protected $options     = [
        '--email'    => 'Admin user email address (or ADMIN_EMAIL env)',
        '--name'     => 'Admin user display name (or ADMIN_NAME env)',
        '--password' => 'Admin user password (or ADMIN_PASSWORD env). If omitted, will prompt or generate.',
    ];

    public function run(array $params)
    {
        $email = CLI::getOption('email') ?: (getenv('ADMIN_EMAIL') ?: (string) env('ADMIN_EMAIL', ''));
        $name = CLI::getOption('name') ?: (getenv('ADMIN_NAME') ?: (string) env('ADMIN_NAME', ''));
        $password = CLI::getOption('password') ?: (getenv('ADMIN_PASSWORD') ?: (string) env('ADMIN_PASSWORD', ''));

        if (empty($email)) {
            $email = CLI::prompt('Admin Email address');
        }
        $email = IdentityValidator::normalizeEmail(trim($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            CLI::error('Invalid email address format.');
            return 1;
        }

        if (empty($name)) {
            $name = CLI::prompt('Admin Display Name', 'TenderHub Administrator');
        }
        $name = trim($name);

        $generated = false;
        if (empty($password)) {
            if (is_cli() && ! empty($_SERVER['argv']) && in_array('--no-interaction', $_SERVER['argv'], true)) {
                $password = bin2hex(random_bytes(6)) . 'A1!' . bin2hex(random_bytes(6));
                $generated = true;
            } else {
                $password = CLI::prompt('Admin Password (min 8 chars, mixed case, number, symbol)');
            }
        }

        $pwdCheck = IdentityValidator::validatePassword($password);
        if (! $pwdCheck['valid']) {
            CLI::error('Password does not meet complexity requirements: ' . $pwdCheck['error']);
            return 1;
        }

        $db = db_connect('default');

        // 1. Resolve or create staff organisation
        $staffOrg = $db->table('organisations')
            ->where('type', 'staff')
            ->get()
            ->getFirstRow('array');

        $now = date('Y-m-d H:i:s');

        if (! $staffOrg) {
            $db->table('organisations')->insert([
                'name'               => 'TenderHub',
                'slug'               => 'tenderhub-staff-' . bin2hex(random_bytes(2)),
                'type'               => 'staff',
                'plan'               => 'staff',
                'sub_status'         => 'active',
                'seats'              => 50,
                'verify_state'       => 'verified',
                'verified_at'        => $now,
                'approval_threshold' => 100000000,
                'standstill_days'    => 7,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $staffOrgId = (int) $db->insertID();
            CLI::write('Created new staff organisation [id=' . $staffOrgId . '].', 'yellow');
        } else {
            $staffOrgId = (int) $staffOrg['id'];
            if ($staffOrg['plan'] !== 'staff' || $staffOrg['sub_status'] !== 'active') {
                $db->table('organisations')->where('id', $staffOrgId)->update([
                    'plan'       => 'staff',
                    'sub_status' => 'active',
                    'updated_at' => $now,
                ]);
                CLI::write('Updated staff organisation [id=' . $staffOrgId . '] plan to staff.', 'yellow');
            }
        }

        // 2. Resolve or create admin user
        $userModel = model('App\Models\UserModel');
        $existingUser = $userModel->where('email', $email)->first();

        $pwdHash = password_hash($password, PASSWORD_DEFAULT);

        if ($existingUser) {
            $userModel->update($existingUser['id'], [
                'org_id'            => $staffOrgId,
                'name'              => $name,
                'password_hash'     => $pwdHash,
                'role'              => 'admin',
                'user_group'        => 'staff',
                'status'            => 'active',
                'email_verified_at' => $now,
                'updated_at'        => $now,
            ]);
            CLI::write("Staff administrator account [{$email}] successfully updated.", 'green');
        } else {
            $userModel->insert([
                'org_id'            => $staffOrgId,
                'name'              => $name,
                'email'             => $email,
                'password_hash'     => $pwdHash,
                'role'              => 'admin',
                'user_group'        => 'staff',
                'status'            => 'active',
                'email_verified_at' => $now,
                'free_views'        => 0,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            CLI::write("Staff administrator account [{$email}] successfully provisioned.", 'green');
        }

        if ($generated) {
            CLI::write('A temporary secure password was generated for this account.', 'cyan');
        }

        return 0;
    }
}