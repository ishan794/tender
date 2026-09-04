<?php

namespace App\Libraries\Ingestion;

/**
 * DeduplicationService
 * Prevents identical or near-identical tenders from being scraped twice.
 */
class DeduplicationService
{
    /**
     * Generates a deterministic content fingerprint (SHA-256).
     */
    public static function fingerprint(string $title, ?string $refNo, ?string $closingDate): string
    {
        $normalized = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $title)));
        $ref        = strtolower(trim($refNo ?? ''));
        $date       = trim($closingDate ?? '');

        return hash('sha256', $normalized . '|' . $ref . '|' . $date);
    }

    /**
     * Checks if notice is a duplicate of an existing record.
     */
    public static function isDuplicate(string $slug, string $fingerprint): bool
    {
        $db = \Config\Database::connect();

        // 1. Exact slug match
        $slugExists = $db->table('notices')->where('slug', $slug)->countAllResults() > 0;
        if ($slugExists) {
            return true;
        }

        // 2. Hash match (if notices table has fingerprint column or title/ref check)
        $hashExists = $db->table('notices')
            ->where('source_hash', $fingerprint)
            ->countAllResults() > 0;

        return $hashExists;
    }
}
