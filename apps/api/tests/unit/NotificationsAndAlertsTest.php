<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use App\Libraries\Notifications\AlertMatcherService;
use App\Libraries\Notifications\NotificationService;
use App\Models\AlertProfileModel;

/**
 * @internal
 */
final class NotificationsAndAlertsTest extends CIUnitTestCase
{
    private AlertMatcherService $matcher;
    private NotificationService $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect();
        $this->notifier = new NotificationService();
        $this->matcher  = new AlertMatcherService($this->notifier);
    }

    public function testAlertMatcherMatchesCorrectNoticeCriteria(): void
    {
        $profile = [
            'kinds'          => 'tender',
            'category_slugs' => 'civil-works,information-technology',
            'district_slugs' => 'colombo,gampaha',
            'keywords'       => 'bridge,culvert,highway',
            'min_value'      => 10000000.00,
            'max_value'      => 500000000.00,
        ];

        // 1. Exact match
        $matchingNotice = [
            'kind'            => 'tender',
            'category_slug'   => 'civil-works',
            'district_slug'   => 'colombo',
            'title'           => 'Construction of Kelani Bridge Extension',
            'summary'         => 'Four-lane highway and culvert works in Colombo',
            'estimated_value' => 250000000.00,
        ];
        $this->assertTrue($this->matcher->matchesNotice($matchingNotice, $profile));

        // 2. Value below minimum -> reject
        $lowValueNotice = array_merge($matchingNotice, ['estimated_value' => 5000000.00]);
        $this->assertFalse($this->matcher->matchesNotice($lowValueNotice, $profile));

        // 3. Value above maximum -> reject
        $highValueNotice = array_merge($matchingNotice, ['estimated_value' => 900000000.00]);
        $this->assertFalse($this->matcher->matchesNotice($highValueNotice, $profile));

        // 4. District mismatch -> reject
        $wrongDistrictNotice = array_merge($matchingNotice, ['district_slug' => 'kandy']);
        $this->assertFalse($this->matcher->matchesNotice($wrongDistrictNotice, $profile));

        // 5. Category mismatch -> reject
        $wrongCatNotice = array_merge($matchingNotice, ['category_slug' => 'pharmaceuticals']);
        $this->assertFalse($this->matcher->matchesNotice($wrongCatNotice, $profile));

        // 6. Keyword mismatch -> reject
        $noKeywordNotice = array_merge($matchingNotice, [
            'title'   => 'Supply of Hospital Beds',
            'summary' => 'Procurement of furniture',
        ]);
        $this->assertFalse($this->matcher->matchesNotice($noKeywordNotice, $profile));
    }

    public function testNotificationServiceDeliversInAppAndLogsHonestSkippedExternal(): void
    {
        $userId = 1;
        $orgId  = 1;
        $title  = 'Urgent Notice: Bid Opening Scheduled';
        $body   = 'Tender Kelani Bridge opening at 10:00 AM';
        $link   = '/tenders/kelani-bridge';
        $channels = ['in_app', 'email', 'sms', 'whatsapp'];

        $nid = $this->notifier->notify($userId, $orgId, 'bid_opening', $title, $body, $link, $channels);
        $this->assertGreaterThan(0, $nid);

        // Verify notifications row
        $notif = $this->db->table('notifications')->where('id', $nid)->get()->getFirstRow('array');
        $this->assertNotNull($notif);
        $this->assertSame($title, $notif['title']);
        $this->assertNull($notif['read_at']);

        // Verify deliveries
        $deliveries = $this->db->table('notification_deliveries')
            ->where('notification_id', $nid)
            ->get()->getResultArray();

        $this->assertCount(4, $deliveries);

        $channelStatusMap = [];
        foreach ($deliveries as $d) {
            $channelStatusMap[$d['channel']] = [
                'status' => $d['status'],
                'detail' => $d['detail'],
            ];
        }

        // In-app must be delivered
        $this->assertSame('delivered', $channelStatusMap['in_app']['status']);

        // Email, SMS, WhatsApp must be skipped with explicit blocked provider reason
        $this->assertSame('skipped', $channelStatusMap['email']['status']);
        $this->assertStringContainsString('BLOCKED', $channelStatusMap['email']['detail']);

        $this->assertSame('skipped', $channelStatusMap['sms']['status']);
        $this->assertStringContainsString('BLOCKED', $channelStatusMap['sms']['detail']);

        $this->assertSame('skipped', $channelStatusMap['whatsapp']['status']);
        $this->assertStringContainsString('BLOCKED', $channelStatusMap['whatsapp']['detail']);

        // Verify Event Ledger recorded the notification
        $event = $this->db->table('event_ledger')
            ->where('entity_type', 'notification')
            ->where('entity_id', $nid)
            ->where('event_type', 'notification.created')
            ->get()->getFirstRow('array');

        $this->assertNotNull($event);
        $this->assertStringContainsString("Notification #{$nid}", $event['summary']);
    }

    public function testAlertMatcherDispatchesNoticeAndPreventsDuplicates(): void
    {
        $uniqueRef = 'TEST-ALERT-' . bin2hex(random_bytes(3));
        $uniqueSlug = 'test-water-supply-' . bin2hex(random_bytes(2));

        // Create notice
        $this->db->table('notices')->insert([
            'kind'            => 'tender',
            'reference'       => $uniqueRef,
            'slug'            => $uniqueSlug,
            'title'           => 'Water Supply and Sanitation Pipeline Construction',
            'summary'         => 'Extensive pipeline works in Western Province',
            'description'     => 'Tenders invited for pipe laying and pump houses.',
            'category_id'     => 1,
            'district_id'     => 1,
            'sector'          => 'utilities',
            'estimated_value' => 50000000.00,
            'status'          => 'published',
            'published_at'    => date('Y-m-d H:i:s'),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
        $noticeId = (int) $this->db->insertID();

        // Create active alert profile matching this notice
        $profileId = (int) $this->db->table('alert_profiles')->insert([
            'org_id'         => 1,
            'user_id'        => 1,
            'name'           => 'Western Utilities',
            'kinds'          => 'tender',
            'keywords'       => 'water,pipeline,sanitation',
            'channels'       => 'in_app,email',
            'active'         => 1,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        // First dispatch should create 1 notification
        $dispatched = $this->matcher->dispatchForNotice($noticeId);
        $this->assertGreaterThanOrEqual(1, $dispatched);

        // Second dispatch for identical notice must deduplicate and return 0 new alerts
        $secondDispatch = $this->matcher->dispatchForNotice($noticeId);
        $this->assertSame(0, $secondDispatch, 'Duplicate alert must be suppressed.');
    }

    public function testAlertProfileModelMatchIdsQuery(): void
    {
        $model = model(AlertProfileModel::class);
        $profile = [
            'kinds'          => 'tender',
            'category_slugs' => '',
            'district_slugs' => '',
            'keywords'       => 'Tender',
            'min_value'      => null,
            'max_value'      => null,
        ];

        $ids = $model->matchIds($profile, date('Y-m-d H:i:s', strtotime('-60 days')));
        $this->assertIsArray($ids);
    }
}
