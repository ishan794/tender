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

        // On-read integrity verification: fail closed if disk blob is tampered or corrupted.
        if (! hash_equals((string) $doc['sha256'], hash('sha256', $bytes))) {
            return problem(500, 'integrity_check_failed', 'Document integrity check failed: file corrupted or tampered.');
        }

        // The path comes from the hash. A stored file name is NEVER used as a
        // path; only the display name comes from the row, and it is sanitised
        // before it reaches a header.
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) $doc['name']);

        // Download logging — both for queryable counter and append-only Event Ledger.
        try {
            $db = db_connect();
            $db->table('document_downloads')->insert([
                'notice_document_id' => (int) $doc['id'],
                'user_id' => $userId > 0 ? $userId : null,
                'ip' => $this->request->getIPAddress(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $proc = $db->table('procurements')->where('notice_id', (int) $doc['notice_id'])->get()->getFirstRow('array');
            $user = $db->table('users')->where('id', $userId)->get()->getFirstRow('array');
            $actor = $user ? [
                'id'   => (int) $user['id'],
                'name' => $user['name'] ?? null,
                'role' => $user['role'] ?? null,
                'org'  => (int) ($user['org_id'] ?? 0),
            ] : null;

            if ($proc) {
                service('eventLedger')->record('procurement', (int) $proc['id'], 'doc.downloaded', "Document {$doc['name']} downloaded", [
                    'doc_id' => (int) $doc['id'],
                    'name'   => $doc['name'],
                    'sha256' => $doc['sha256'],
                ], $actor);
            } else {
                service('eventLedger')->record('notice', (int) $doc['notice_id'], 'doc.downloaded', "Document {$doc['name']} downloaded", [
                    'doc_id' => (int) $doc['id'],
                    'name'   => $doc['name'],
                    'sha256' => $doc['sha256'],
                ], $actor);
            }
        } catch (\Throwable $e) {
            log_message('error', 'download log failed: ' . $e->getMessage());
        }

        return $this->response
            ->setStatusCode(200)
            ->setContentType($doc['mime'] ?: 'application/octet-stream')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->setHeader('ETag', '"' . $doc['sha256'] . '"')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($bytes);
    }
}
