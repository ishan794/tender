<?php

namespace App\Transformers;

/**
 * THE PAYWALL.
 *
 * One class decides what leaves the server, and it is deliberately the only
 * one. The rule:
 *
 *   A withheld field is NOT SERIALISED. It never reaches the browser in any
 *   form — not blurred, not truncated, not hidden behind CSS, not sitting in a
 *   React props payload. The `locked` array carries only the NAMES of withheld
 *   fields so the interface can render an honest explanation panel in their
 *   place.
 *
 * Two leaks were caught in Rev 3.0 and both were the same shape: a field
 * withheld from the visible page but still present in the payload (JSON-LD in
 * one case, an RSC client-component prop in the other). The standing rule that
 * came out of it — the transformer decides what exists, not the component. A
 * user interface that hides what the payload contains is not confidentiality,
 * it is a leak with a stylesheet over it.
 */
final class NoticeTransformer
{
    /** Always public. The closing date is never masked at any tier: it is the
     *  one thing this product sells, and a bidder who misses a tender because
     *  of us does not come back. */
    private const ALWAYS = [
        'id', 'kind', 'reference', 'slug', 'title', 'sector', 'category', 'district',
        'estimated_value', 'currency', 'closing_at', 'opening_at', 'status',
        'documents_count', 'is_native', 'verified',
        'locale', 'is_fallback', 'title_si', 'title_ta', 'translations',
    ];

    private const RELEASE = [
        'guest'    => ['summary_teaser'],
        'free'     => ['summary_teaser', 'summary', 'summary_si', 'summary_ta', 'description_teaser', 'buyer', 'published_at'],
        'paid'     => ['summary_teaser', 'summary', 'summary_si', 'summary_ta', 'description_teaser', 'buyer', 'published_at',
                       'description', 'description_si', 'description_ta', 'documents', 'contact_officer', 'contact_phone', 'contact_email',
                       'source_url', 'document_fee', 'bid_security'],
    ];

    private const GATED = [
        'summary', 'summary_si', 'summary_ta', 'description', 'description_si', 'description_ta', 'description_teaser', 'buyer', 'published_at', 'documents',
        'contact_officer', 'contact_phone', 'contact_email', 'source_url',
        'document_fee', 'bid_security',
    ];

    public static function tierFor(?array $viewer): string
    {
        if ($viewer === null) {
            return 'guest';
        }

        $plan = $viewer['plan'] ?? 'free';
        if (in_array($plan, ['business', 'publish', 'enterprise', 'staff'], true)
            && ($viewer['sub_status'] ?? 'active') !== 'expired') {
            return 'paid';
        }

        return 'free';
    }

    public static function collection(array $rows, string $tier, array $extra = [], string $locale = 'en'): array
    {
        return array_map(static fn (array $r) => self::one($r, $tier, $extra[$r['id']] ?? [], $locale), $rows);
    }

    public static function one(array $row, string $tier, array $extra = [], string $locale = 'en'): array
    {
        $released = self::RELEASE[$tier] ?? self::RELEASE['guest'];

        $isFallback = false;
        $title = $row['title'];
        $summary = $row['summary'] ?? null;
        $description = $row['description'] ?? null;
        $category = $row['category_name'] ?? null;
        $district = $row['district_name'] ?? null;
        $buyer = $row['authority_name'] ?? $row['org_name'] ?? null;

        if ($locale === 'si') {
            if (! empty($row['title_si'])) {
                $title = $row['title_si'];
            } else {
                $isFallback = true;
            }
            if (! empty($row['summary_si'])) {
                $summary = $row['summary_si'];
            }
            if (! empty($row['description_si'])) {
                $description = $row['description_si'];
            }
            if (! empty($row['category_name_si'])) {
                $category = $row['category_name_si'];
            }
            if (! empty($row['district_name_si'])) {
                $district = $row['district_name_si'];
            }
            if (! empty($row['authority_name_si'])) {
                $buyer = $row['authority_name_si'];
            }
        } elseif ($locale === 'ta') {
            if (! empty($row['title_ta'])) {
                $title = $row['title_ta'];
            } else {
                $isFallback = true;
            }
            if (! empty($row['summary_ta'])) {
                $summary = $row['summary_ta'];
            }
            if (! empty($row['description_ta'])) {
                $description = $row['description_ta'];
            }
            if (! empty($row['category_name_ta'])) {
                $category = $row['category_name_ta'];
            }
            if (! empty($row['district_name_ta'])) {
                $district = $row['district_name_ta'];
            }
            if (! empty($row['authority_name_ta'])) {
                $buyer = $row['authority_name_ta'];
            }
        }

        $translations = [
            'en' => [
                'title'       => $row['title'] ?? null,
                'summary'     => in_array('summary', $released, true) ? ($row['summary'] ?? null) : null,
                'description' => in_array('description', $released, true) ? ($row['description'] ?? null) : null,
                'category'    => $row['category_name'] ?? null,
                'district'    => $row['district_name'] ?? null,
                'buyer'       => in_array('buyer', $released, true) ? ($row['authority_name'] ?? $row['org_name'] ?? null) : null,
            ],
            'si' => [
                'title'       => $row['title_si'] ?? null,
                'summary'     => in_array('summary', $released, true) ? ($row['summary_si'] ?? null) : null,
                'description' => in_array('description', $released, true) ? ($row['description_si'] ?? null) : null,
                'category'    => $row['category_name_si'] ?? null,
                'district'    => $row['district_name_si'] ?? null,
                'buyer'       => in_array('buyer', $released, true) ? ($row['authority_name_si'] ?? null) : null,
            ],
            'ta' => [
                'title'       => $row['title_ta'] ?? null,
                'summary'     => in_array('summary', $released, true) ? ($row['summary_ta'] ?? null) : null,
                'description' => in_array('description', $released, true) ? ($row['description_ta'] ?? null) : null,
                'category'    => $row['category_name_ta'] ?? null,
                'district'    => $row['district_name_ta'] ?? null,
                'buyer'       => in_array('buyer', $released, true) ? ($row['authority_name_ta'] ?? null) : null,
            ],
        ];

        $full = [
            'id'              => (int) $row['id'],
            'kind'            => $row['kind'],
            'reference'       => $row['reference'],
            'slug'            => $row['slug'],
            'title'           => $title,
            'title_si'        => $row['title_si'] ?? null,
            'title_ta'        => $row['title_ta'] ?? null,
            'locale'          => $locale,
            'is_fallback'     => $isFallback,
            'translations'    => $translations,
            'sector'          => $row['sector'] ?? 'government',
            'category'        => $category,
            'category_slug'   => $row['category_slug'] ?? null,
            'district'        => $district,
            'district_slug'   => $row['district_slug'] ?? null,
            'estimated_value' => isset($row['estimated_value']) && $row['estimated_value'] !== null ? (float) $row['estimated_value'] : null,
            'currency'        => $row['currency'] ?? 'LKR',
            'closing_at'      => $row['closing_at'] ?? null,
            'opening_at'      => $row['opening_at'] ?? null,
            'status'          => self::liveStatus($row),
            'documents_count' => (int) ($row['documents_count'] ?? 0),
            'is_native'       => ! empty($row['org_id']),
            'verified'        => (bool) ($row['verified'] ?? false),

            'summary_teaser'  => self::firstLine($summary ?? ''),
            'summary'         => $summary,
            'summary_si'      => $row['summary_si'] ?? null,
            'summary_ta'      => $row['summary_ta'] ?? null,
            'description'     => $description,
            'description_si'  => $row['description_si'] ?? null,
            'description_ta'  => $row['description_ta'] ?? null,
            'description_teaser' => self::truncate($description ?? '', 320),
            'buyer'           => $buyer,
            'published_at'    => $row['published_at'] ?? null,
            'contact_officer' => $row['contact_officer'] ?? null,
            'contact_phone'   => $row['contact_phone'] ?? null,
            'contact_email'   => $row['contact_email'] ?? null,
            'source_url'      => $row['source_url'] ?? null,
            'document_fee'    => isset($row['document_fee']) ? (float) $row['document_fee'] : null,
            'bid_security'    => isset($row['bid_security']) ? (float) $row['bid_security'] : null,
            'documents'       => $extra['documents'] ?? [],
        ];

        if (isset($extra['auction'])) {
            $full['auction'] = $extra['auction'];
        }

        $out    = [];
        $locked = [];

        foreach ($full as $key => $value) {
            if (in_array($key, self::ALWAYS, true) || $key === 'category_slug' || $key === 'district_slug' || $key === 'auction') {
                $out[$key] = $value;
                continue;
            }

            if (in_array($key, $released, true)) {
                $out[$key] = $value;
                continue;
            }

            if (in_array($key, self::GATED, true)) {
                $locked[] = $key;
            }
        }

        $out['locked'] = array_values(array_unique($locked));
        $out['tier']   = $tier;

        return $out;
    }

    public static function liveStatus(array $row): string
    {
        if (($row['status'] ?? '') !== 'published') {
            return $row['status'] ?? 'draft';
        }

        $closing = strtotime((string) ($row['closing_at'] ?? '')) ?: 0;
        if ($closing === 0) {
            return 'published';
        }

        $now = time();
        if ($closing < $now) {
            return 'closed';
        }

        return $closing - $now <= 7 * 86400 ? 'closing_soon' : 'live';
    }

    private static function firstLine(string $text): string
    {
        $text = trim(strip_tags($text));
        if ($text === '') {
            return '';
        }
        $parts = preg_split('/(?<=[.!?])\s+/', $text, 2);

        return trim($parts[0] ?? $text);
    }

    private static function truncate(string $text, int $len): string
    {
        $text = trim(strip_tags($text));

        return strlen($text) <= $len ? $text : rtrim(substr($text, 0, $len)) . '…';
    }
}
