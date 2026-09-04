<?php

namespace App\Controllers\Api\V1\Files;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\DocumentStore;

/**
 * This route carries NO auth filter, by design: the signature IS the
 * authorisation. It was minted by an endpoint that had already checked the
 * subscription, and it is re-checked on the way in. That is what lets a
 * download survive a redirect, a download manager, or a browser that will not
 * attach cookies to a file request.
 */
class FileController extends BaseApiController
{
    public function document(int $id)
    {
        $userId  = (int) $this->request->getGet('u');
        $expires = (int) $this->request->getGet('e');
        $sig     = (string) $this->request->getGet('s');

        // All four refusals return the SAME message. Distinguishing them tells
        // an attacker which part of the forgery to fix.
        $deny = static fn () => problem(403, 'invalid_link', 'This download link is not valid.');

        if ($id <= 0 || $userId <= 0 || $expires <= 0 || $sig === '') {
            return $deny();
        }

        if (! DocumentStore::verify($id, $userId, $expires, $sig)) {
            return $deny();
        }

        $doc = model('App\Models\NoticeDocumentModel')->find($id);
        if (! $doc || ! $doc['path']) {
            return $deny();
        }

        $store = new DocumentStore();
        $bytes = $store->read($doc['path']);
        if ($bytes === null) {
            return $deny();
        }

        // The path comes from the hash. A stored file name is NEVER used as a
        // path; only the display name comes from the row, and it is sanitised
        // before it reaches a header.
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) $doc['name']);

        // Download logging — who pulled which document, for the audit trail.
        try {
            db_connect()->table('document_downloads')->insert([
                'notice_document_id' => (int) $doc['id'],
                'user_id' => isset($this->request->userId) ? (int) $this->request->userId : null,
                'ip' => $this->request->getIPAddress(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'download log failed: ' . $e->getMessage());
        }

        return $this->response
            ->setStatusCode(200)
            ->setContentType($doc['mime'] ?: 'application/octet-stream')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('ETag', '"' . $doc['sha256'] . '"')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($bytes);
    }
}
