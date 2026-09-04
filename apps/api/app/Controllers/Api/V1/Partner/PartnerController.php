<?php

namespace App\Controllers\Api\V1\Partner;

use App\Controllers\Api\V1\BaseApiController;
use App\Transformers\NoticeTransformer;

class PartnerController extends BaseApiController
{
    /**
     * Cursor paging, not page numbers. A partner polling every ten minutes needs
     * "everything since the last thing I saw"; offset paging silently skips rows
     * whenever new notices arrive between two requests. The cursor is handed
     * back rather than left for the partner to derive.
     */
    public function notices()
    {
        $cursor = (int) ($this->request->getGet('cursor') ?? 0);
        $limit  = min(200, max(1, (int) ($this->request->getGet('limit') ?? 50)));

        $rows = model('App\Models\NoticeModel')->partnerFeed($cursor, $limit);
        $next = $rows ? (int) $rows[count($rows) - 1]['id'] : $cursor;

        return $this->ok(NoticeTransformer::collection($rows, 'paid'), [
            'cursor' => $next,
            'has_more' => count($rows) === $limit,
            'next' => count($rows) === $limit ? '/api/v1/partner/notices?cursor=' . $next . '&limit=' . $limit : null,
        ]);
    }

    public function registerWebhook()
    {
        $in    = $this->body();
        $url   = (string) ($in['url'] ?? '');
        $event = (string) ($in['event'] ?? '');
        $orgId = (int) $this->request->orgId;

        $dispatcher = new \App\Libraries\Webhooks\WebhookDispatcher();
        $res = $dispatcher->register($orgId, $url, $event);

        if (! $res['ok']) {
            return problem($res['status'] ?? 422, 'validation_failed', $res['error'], $res['allowed'] ?? []);
        }

        return $this->ok([
            'id'             => $res['id'],
            'url'            => $res['url'],
            'event'          => $res['event'],
            'signing_secret' => $res['signing_secret'],
        ], [
            'warning' => $res['warning'],
        ], 201);
    }

    public function listWebhooks()
    {
        $orgId = (int) $this->request->orgId;
        $dispatcher = new \App\Libraries\Webhooks\WebhookDispatcher();
        return $this->ok($dispatcher->listForOrg($orgId));
    }

    public function deleteWebhook(int $id)
    {
        $orgId = (int) $this->request->orgId;
        $dispatcher = new \App\Libraries\Webhooks\WebhookDispatcher();
        $deleted = $dispatcher->deleteForOrg($orgId, $id);

        if (! $deleted) {
            return problem(404, 'not_found', 'Webhook not found or does not belong to your organisation.');
        }

        return $this->ok(['deleted' => true]);
    }
}
