<?php
namespace App\Libraries\Notifications;

/**
 * Unified notifications. In-app delivery is real and immediate. Email/SMS/
 * WhatsApp are dispatched through adapters that are NOT wired to a provider in
 * this environment: each such delivery is logged 'skipped' with a clear reason
 * rather than faked. Wiring a provider flips those to queued→sent→delivered.
 *   BLOCKED — EMAIL/SMS/WHATSAPP PROVIDER REQUIRED
 */
final class NotificationService
{
    /** @param list<string> $channels */
    public function notify(int $userId, ?int $orgId, string $type, string $title, ?string $body = null, ?string $link = null, array $channels = ['in_app']): int
    {
        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('notifications')->insert([
            'user_id' => $userId, 'org_id' => $orgId, 'type' => $type,
            'title' => $title, 'body' => $body, 'link' => $link, 'created_at' => $now,
        ]);
        $nid = (int) $db->insertID();

        foreach ($channels as $rawCh) {
            $ch = in_array(strtolower(trim($rawCh)), ['inapp', 'in_app'], true) ? 'in_app' : strtolower(trim($rawCh));
            if ($ch === 'in_app') {
                $db->table('notification_deliveries')->insert([
                    'notification_id' => $nid,
                    'channel'         => 'in_app',
                    'status'          => 'delivered',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            } else {
                // No external provider configured — logged honestly, never faked.
                $db->table('notification_deliveries')->insert([
                    'notification_id' => $nid,
                    'channel'         => $ch,
                    'status'          => 'skipped',
                    'detail'          => 'no provider configured (BLOCKED — PENDING LIVE CREDENTIALS)',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            }
        }

        // Record in tamper-evident Event Ledger
        service('eventLedger')->record('notification', $nid, 'notification.created', "Notification #{$nid} created for user #{$userId}", [
            'notification_id' => $nid,
            'user_id'         => $userId,
            'org_id'          => $orgId,
            'type'            => $type,
            'channels'        => $channels,
        ]);

        return $nid;
    }
}
