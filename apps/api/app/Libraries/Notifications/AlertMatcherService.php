<?php

namespace App\Libraries\Notifications;

use App\Models\AlertProfileModel;
use App\Models\NoticeModel;

/**
 * AlertMatcherService
 *
 * Matches newly published or existing notices against active subscriber alert profiles,
 * ensuring strict tenant isolation, deduplication, and multichannel dispatch.
 */
class AlertMatcherService
{
    private \CodeIgniter\Database\BaseConnection $db;
    private NotificationService $notificationService;

    public function __construct(?NotificationService $notificationService = null)
    {
        $this->db = \Config\Database::connect();
        $this->notificationService = $notificationService ?? new NotificationService();
    }

    /**
     * Checks if a single notice satisfies an alert profile's criteria.
     */
    public function matchesNotice(array $notice, array $profile): bool
    {
        // 1. Kind check
        if (! empty($profile['kinds'])) {
            $kinds = array_filter(array_map('trim', explode(',', (string) $profile['kinds'])));
            if (! empty($kinds) && ! in_array($notice['kind'] ?? 'tender', $kinds, true)) {
                return false;
            }
        }

        // 2. Category slug check
        if (! empty($profile['category_slugs'])) {
            $catSlugs = array_filter(array_map('trim', explode(',', (string) $profile['category_slugs'])));
            if (! empty($catSlugs)) {
                $noticeCatSlug = $notice['category_slug'] ?? null;
                if (! $noticeCatSlug && ! empty($notice['category_id'])) {
                    $catRow = $this->db->table('categories')->where('id', $notice['category_id'])->get()->getFirstRow('array');
                    $noticeCatSlug = $catRow['slug'] ?? null;
                }
                if (! $noticeCatSlug || ! in_array($noticeCatSlug, $catSlugs, true)) {
                    return false;
                }
            }
        }

        // 3. District slug check
        if (! empty($profile['district_slugs'])) {
            $distSlugs = array_filter(array_map('trim', explode(',', (string) $profile['district_slugs'])));
            if (! empty($distSlugs)) {
                $noticeDistSlug = $notice['district_slug'] ?? null;
                if (! $noticeDistSlug && ! empty($notice['district_id'])) {
                    $distRow = $this->db->table('districts')->where('id', $notice['district_id'])->get()->getFirstRow('array');
                    $noticeDistSlug = $distRow['slug'] ?? null;
                }
                if (! $noticeDistSlug || ! in_array($noticeDistSlug, $distSlugs, true)) {
                    return false;
                }
            }
        }

        // 4. Value range check
        $val = isset($notice['estimated_value']) && is_numeric($notice['estimated_value']) ? (float) $notice['estimated_value'] : null;
        if ($val !== null) {
            if ($profile['min_value'] !== null && $profile['min_value'] !== '' && $val < (float) $profile['min_value']) {
                return false;
            }
            if ($profile['max_value'] !== null && $profile['max_value'] !== '' && $val > (float) $profile['max_value']) {
                return false;
            }
        }

        // 5. Keywords check (in title or summary)
        if (! empty($profile['keywords'])) {
            $keywords = array_filter(array_map('trim', explode(',', (string) $profile['keywords'])));
            if (! empty($keywords)) {
                $haystack = mb_strtolower(($notice['title'] ?? '') . ' ' . ($notice['summary'] ?? ''));
                $matchedKeyword = false;
                foreach ($keywords as $kw) {
                    if (str_contains($haystack, mb_strtolower($kw))) {
                        $matchedKeyword = true;
                        break;
                    }
                }
                if (! $matchedKeyword) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Dispatches notifications to all matching active alert profiles for a notice.
     */
    public function dispatchForNotice(int|array $noticeOrId): int
    {
        if (is_int($noticeOrId)) {
            $notice = $this->db->table('notices')->where('id', $noticeOrId)->get()->getFirstRow('array');
        } else {
            $notice = $noticeOrId;
        }

        if (! $notice || ($notice['status'] ?? '') !== 'published') {
            return 0;
        }

        // Fetch all active profiles
        $profiles = $this->db->table('alert_profiles')
            ->where('active', 1)
            ->get()->getResultArray();

        $dispatched = 0;
        $link = '/tenders/' . ($notice['slug'] ?? $notice['id']);

        foreach ($profiles as $profile) {
            $userId = (int) $profile['user_id'];
            $orgId  = (int) $profile['org_id'];

            if (! $this->matchesNotice($notice, $profile)) {
                continue;
            }

            // Deduplication check: check if an alert for this notice was already sent to this user
            $alreadyNotified = $this->db->table('notifications')
                ->where('user_id', $userId)
                ->where('type', 'tender_alert')
                ->where('link', $link)
                ->countAllResults() > 0;

            if ($alreadyNotified) {
                continue;
            }

            // Parse channels
            $channels = array_filter(array_map('trim', explode(',', (string) ($profile['channels'] ?? 'in_app'))));
            if (empty($channels)) {
                $channels = ['in_app'];
            }

            $title = 'New Tender Match: ' . mb_substr($notice['title'], 0, 100);
            $body  = "Matched alert profile \"{$profile['name']}\". Reference: {$notice['reference']}";

            $nid = $this->notificationService->notify($userId, $orgId, 'tender_alert', $title, $body, $link, $channels);

            if ($nid > 0) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /**
     * Batch matches all published notices published since a given timestamp.
     */
    public function dispatchBatch(?string $since = null): array
    {
        $since ??= date('Y-m-d H:i:s', strtotime('-24 hours'));

        $notices = $this->db->table('notices')
            ->where('status', 'published')
            ->where('published_at >=', $since)
            ->get()->getResultArray();

        $totalDispatched = 0;
        foreach ($notices as $n) {
            $totalDispatched += $this->dispatchForNotice($n);
        }

        return [
            'notices_scanned' => count($notices),
            'alerts_created'  => $totalDispatched,
            'since'           => $since,
        ];
    }
}
