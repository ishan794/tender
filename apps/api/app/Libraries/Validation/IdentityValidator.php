<?php

namespace App\Libraries\Validation;

/**
 * Validates Sri Lankan identity numbers (NIC), Business Registration Numbers (BRN),
 * email normalization, and password security policies.
 */
class IdentityValidator
{
    /**
     * Sri Lankan National Identity Card pattern:
     * - Legacy: 9 digits followed by 'V' or 'X' (case-insensitive)
     * - Modern: 12 digits
     */
    public const NIC_REGEX = '/^(?:[0-9]{9}[vVxX]|[0-9]{12})$/';

    /**
     * Sri Lankan Business Registration Number pattern:
     * Covers ROC company numbers (PV/PB/GA prefixes) and provincial business names.
     */
    public const BRN_REGEX = '/^[a-zA-Z0-9\/\-\s]{3,60}$/';

    /**
     * Disallowed placeholder registration numbers.
     */
    private const PLACEHOLDER_BRNS = [
        'n/a', 'na', 'none', 'nil', 'null', 'test', 'unknown', 'pending',
        '0', '00', '000', '0000', '00000', '123', '1234', '12345',
    ];

    /**
     * Validates Sri Lankan NIC.
     */
    public static function isValidNic(?string $nic): bool
    {
        if ($nic === null || trim($nic) === '') {
            return false;
        }

        return (bool) preg_match(self::NIC_REGEX, trim($nic));
    }

    /**
     * Validates Sri Lankan Business Registration Number (BRN) or company registration number.
     */
    public static function isValidBrn(?string $brn): bool
    {
        if ($brn === null || trim($brn) === '') {
            return false;
        }

        $cleaned = trim($brn);
        if (! preg_match(self::BRN_REGEX, $cleaned)) {
            return false;
        }

        if (in_array(strtolower($cleaned), self::PLACEHOLDER_BRNS, true)) {
            return false;
        }

        return true;
    }

    /**
     * Normalizes and cleans email address.
     */
    public static function normalizeEmail(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    /**
     * Normalizes registration number.
     */
    public static function normalizeRegNo(?string $regNo): ?string
    {
        if ($regNo === null || trim($regNo) === '') {
            return null;
        }

        // Standardize whitespace and uppercase prefix
        $cleaned = trim(preg_replace('/\s+/', ' ', $regNo));
        return strtoupper($cleaned);
    }

    /**
     * Validates password against the security policy:
     * - Minimum 8 characters
     * - At least one letter (a-z, A-Z)
     * - At least one numeric digit (0-9)
     * - Must not consist solely of whitespace
     *
     * @return array{valid: bool, error: ?string}
     */
    public static function validatePassword(?string $password): array
    {
        $pwd = (string) $password;

        if (strlen($pwd) < 8) {
            return ['valid' => false, 'error' => 'Password must be at least 8 characters long.'];
        }

        if (trim($pwd) === '') {
            return ['valid' => false, 'error' => 'Password cannot consist purely of whitespace.'];
        }

        if (! preg_match('/[a-zA-Z]/', $pwd)) {
            return ['valid' => false, 'error' => 'Password must contain at least one letter.'];
        }

        if (! preg_match('/[0-9]/', $pwd)) {
            return ['valid' => false, 'error' => 'Password must contain at least one numeric digit.'];
        }

        return ['valid' => true, 'error' => null];
    }
}
