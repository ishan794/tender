<?php
namespace App\Libraries\Monitoring;

/** Records security-relevant events for the admin Security Center. */
final class SecurityMonitor
{
    public static function record(string $kind, string $severity = 'info', ?int $actorId = null, ?string $detail = null): void
    {
        try {
            $req = service('request');
            db_connect()->table('security_events')->insert([
                'kind' => $kind, 'severity' => $severity, 'actor_id' => $actorId,
                'ip' => method_exists($req, 'getIPAddress') ? $req->getIPAddress() : null,
                'detail' => $detail !== null ? substr($detail, 0, 255) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Monitoring must never break the request it observes.
            log_message('error', 'SecurityMonitor failed: ' . $e->getMessage());
        }
    }
}
