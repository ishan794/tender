<?php

namespace App\Controllers\Api\V1\Authority;

use App\Libraries\DocumentStore;

/** Documents, purchasers, clarifications, addenda. */
class SaleController extends WorkspaceBase
{
    public function documents(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        return $this->ok(db_connect()->table('notice_documents')
            ->where('notice_id', $proc['notice_id'])->get()->getResultArray());
    }

    public function upload(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        // Adding a document after closing changes what bidders were asked to
        // price AFTER they priced it. That is what an addendum is for.
        if (strtotime((string) $proc['closing_at']) < time()) {
            return problem(409, 'closed', 'This tender has closed. Issue an addendum instead.');
        }

        $file = $this->request->getFile('file');
        $validFile = $file && ($file->isValid() || (defined('ENVIRONMENT') && ENVIRONMENT === 'testing' && $file->getError() === UPLOAD_ERR_OK && is_file($file->getTempName())));
        if (! $validFile) {
            return problem(422, 'no_file', 'No file received.');
        }

        $ext = strtolower($file->getClientExtension());
        if (! in_array($ext, DocumentStore::ALLOWED, true)) {
            return problem(422, 'bad_type', 'That file type is not accepted.', ['allowed' => DocumentStore::ALLOWED]);
        }
        if ($file->getSize() > DocumentStore::MAX_BYTES) {
            return problem(413, 'too_large', 'Files are capped at 40 MB.');
        }

        $scan = \App\Libraries\Security\VirusScanner::scan($file->getTempName());
        if (! $scan['clean']) {
            return problem(422, 'malware_detected', $scan['reason'] ?? 'Malicious content detected.');
        }

        $store  = new DocumentStore();
        $stored = $store->put((string) file_get_contents($file->getTempName()), $ext);
        $db     = db_connect();

        $db->table('notice_documents')->insert([
            'notice_id' => (int) $proc['notice_id'],
            'name' => $file->getClientName(),
            'kind' => $this->request->getPost('kind') ?: 'bidding',
            'mime' => $file->getClientMimeType(),
            'size_bytes' => $stored['size'], 'sha256' => $stored['sha256'], 'path' => $stored['path'],
            'mirrored_at' => date('Y-m-d H:i:s'),
            'uploaded_by' => (int) $this->request->userId,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $docId = (int) $db->insertID();

        // Immutable version record (content-addressed hash), version numbered per document.
        $db->table('document_versions')->insert([
            'notice_document_id' => $docId, 'version' => 1, 'sha256' => $stored['sha256'],
            'reason' => $this->request->getPost('reason') ?: 'Original', 'effective_date' => date('Y-m-d'),
            'uploaded_by' => (int) $this->request->userId, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->table('notices')->where('id', $proc['notice_id'])->set('documents_count',
            (string) $db->table('notice_documents')->where('notice_id', $proc['notice_id'])->countAllResults(), false)->update();

        service('eventLedger')->record('procurement', $id, 'doc.uploaded', "Document {$file->getClientName()} uploaded", [
            'doc_id' => $docId,
            'name'   => $file->getClientName(),
            'sha256' => $stored['sha256'],
            'size'   => $stored['size'],
        ]);

        return $this->ok(['sha256' => $stored['sha256'], 'deduped' => $stored['deduped'], 'size' => $stored['size'], 'id' => $docId], [], 201);
    }

    public function uploadVersion(int $id, int $docId)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        $doc = model('App\Models\NoticeDocumentModel')->find($docId);
        if (! $doc || (int) $doc['notice_id'] !== (int) $proc['notice_id']) {
            return problem(404, 'not_found', 'No such document.');
        }

        if (strtotime((string) $proc['closing_at']) < time()) {
            return problem(409, 'closed', 'This tender has closed. Issue an addendum instead.');
        }

        $db = db_connect();
        $held = $db->table('legal_holds')
            ->groupStart()
                ->where(['entity_type' => 'procurement', 'entity_id' => $id])
                ->orWhere(['entity_type' => 'notice', 'entity_id' => (int) $proc['notice_id']])
                ->orWhere(['entity_type' => 'document', 'entity_id' => $docId])
            ->groupEnd()
            ->where('released_at', null)
            ->countAllResults() > 0;
        if ($held) {
            return problem(423, 'legal_hold', 'This tender or document is under a legal hold; new versions cannot be uploaded.');
        }

        $file = $this->request->getFile('file');
        $validFile = $file && ($file->isValid() || (defined('ENVIRONMENT') && ENVIRONMENT === 'testing' && $file->getError() === UPLOAD_ERR_OK && is_file($file->getTempName())));
        if (! $validFile) {
            return problem(422, 'no_file', 'No file received.');
        }

        $ext = strtolower($file->getClientExtension());
        if (! in_array($ext, DocumentStore::ALLOWED, true)) {
            return problem(422, 'bad_type', 'That file type is not accepted.', ['allowed' => DocumentStore::ALLOWED]);
        }
        if ($file->getSize() > DocumentStore::MAX_BYTES) {
            return problem(413, 'too_large', 'Files are capped at 40 MB.');
        }

        $scan = \App\Libraries\Security\VirusScanner::scan($file->getTempName());
        if (! $scan['clean']) {
            return problem(422, 'malware_detected', $scan['reason'] ?? 'Malicious content detected.');
        }

        $store  = new DocumentStore();
        $stored = $store->put((string) file_get_contents($file->getTempName()), $ext);

        $db->table('document_versions')->where('notice_document_id', $docId)->update(['superseded' => 1]);

        $maxVer = (int) ($db->table('document_versions')->where('notice_document_id', $docId)->selectMax('version')->get()->getFirstRow('array')['version'] ?? 0);
        $nextVer = $maxVer + 1;

        $db->table('document_versions')->insert([
            'notice_document_id' => $docId,
            'version'            => $nextVer,
            'sha256'             => $stored['sha256'],
            'reason'             => $this->request->getPost('reason') ?: "Version {$nextVer}",
            'effective_date'     => date('Y-m-d'),
            'superseded'         => 0,
            'uploaded_by'        => (int) $this->request->userId,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $db->table('notice_documents')->where('id', $docId)->update([
            'name'        => $file->getClientName(),
            'mime'        => $file->getClientMimeType(),
            'size_bytes'  => $stored['size'],
            'sha256'      => $stored['sha256'],
            'path'        => $stored['path'],
            'mirrored_at' => date('Y-m-d H:i:s'),
            'uploaded_by' => (int) $this->request->userId,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        service('eventLedger')->record('procurement', $id, 'doc.version_added', "New version {$nextVer} uploaded for document {$doc['name']}", [
            'doc_id'  => $docId,
            'version' => $nextVer,
            'sha256'  => $stored['sha256'],
            'size'    => $stored['size'],
        ]);

        return $this->ok([
            'version' => $nextVer,
            'sha256'  => $stored['sha256'],
            'deduped' => $stored['deduped'],
            'size'    => $stored['size'],
        ], [], 201);
    }

    public function documentUrl(int $id, int $docId)
    {
        $proc = $this->procurement($id);
        $doc  = model('App\Models\NoticeDocumentModel')->find($docId);
        if (! $proc || ! $doc || (int) $doc['notice_id'] !== (int) $proc['notice_id']) {
            return problem(404, 'not_found', 'No such document.');
        }

        $exp = time() + 300;
        $u   = (int) $this->request->userId;

        return $this->ok([
            'url' => sprintf('/api/v1/files/documents/%d?u=%d&e=%d&s=%s', $docId, $u, $exp,
                DocumentStore::sign($docId, $u, $exp)),
            'expires_at' => date('c', $exp),
        ]);
    }

    public function deleteDocument(int $id, int $docId)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        $doc = model('App\Models\NoticeDocumentModel')->find($docId);
        if (! $doc || (int) $doc['notice_id'] !== (int) $proc['notice_id']) {
            return problem(404, 'not_found', 'No such document.');
        }

        // Deletion is refused while the tender, notice, or document is under a legal hold.
        $db = db_connect();
        $held = $db->table('legal_holds')
            ->groupStart()
                ->where(['entity_type' => 'procurement', 'entity_id' => $id])
                ->orWhere(['entity_type' => 'notice', 'entity_id' => (int) $proc['notice_id']])
                ->orWhere(['entity_type' => 'document', 'entity_id' => $docId])
            ->groupEnd()
            ->where('released_at', null)
            ->countAllResults() > 0;
        if ($held) {
            return problem(423, 'legal_hold', 'This tender is under a legal hold; its documents cannot be deleted.');
        }

        $db->table('document_downloads')->where('notice_document_id', $docId)->delete();
        $db->table('document_versions')->where('notice_document_id', $docId)->delete();
        $db->table('notice_documents')->where('id', $docId)->where('notice_id', $proc['notice_id'])->delete();

        $db->table('notices')->where('id', $proc['notice_id'])->set('documents_count',
            (string) $db->table('notice_documents')->where('notice_id', $proc['notice_id'])->countAllResults(), false)->update();

        service('eventLedger')->record('procurement', $id, 'doc.deleted', "Document {$doc['name']} deleted", [
            'doc_id' => $docId,
            'sha256' => $doc['sha256'],
        ]);

        return $this->ok(['deleted' => true]);
    }

    /** Version history + download count for a document. */
    public function versions(int $id, int $docId)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }
        $db = db_connect();

        return $this->ok(
            $db->table('document_versions')->where('notice_document_id', $docId)->orderBy('version', 'ASC')->get()->getResultArray(),
            ['downloads' => (int) $db->table('document_downloads')->where('notice_document_id', $docId)->countAllResults()],
        );
    }

    /** The legal record of who is entitled to bid, with the purchased-to-
     *  submitted conversion procurement committees actually watch. */
    public function purchasers(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        $db   = db_connect();
        $rows = $db->table('doc_purchases')
            ->select('doc_purchases.*, organisations.name, organisations.district_id, organisations.cida_grade')
            ->join('organisations', 'organisations.id = doc_purchases.buyer_org_id')
            ->where('doc_purchases.procurement_id', $id)->get()->getResultArray();

        $submitted = $db->table('submissions')->where('procurement_id', $id)->countAllResults();

        return $this->ok($rows, [
            'purchasers' => count($rows),
            'submissions' => $submitted,
            'conversion' => count($rows) ? round($submitted / count($rows) * 100, 1) : null,
        ]);
    }

    public function buyDocuments(int $id)
    {
        $db = db_connect();
        $proc = $db->table('procurements')->where('id', $id)->get()->getFirstRow('array');
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }
        $orgId = (int) $this->request->orgId;
        if ($db->table('doc_purchases')->where('procurement_id', $id)->where('buyer_org_id', $orgId)->countAllResults()) {
            return $this->ok(['already' => true]);
        }
        $db->table('doc_purchases')->insert([
            'procurement_id' => $id, 'buyer_org_id' => $orgId,
            'amount' => (float) ($this->body()['amount'] ?? 0),
            'receipt_no' => 'DP-' . strtoupper(bin2hex(random_bytes(3))),
            'purchased_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->ok(['purchased' => true], [], 201);
    }

    public function clarifications(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        $rows = db_connect()->table('clarifications')
            ->select('id, question, answer, answered_at, created_at')
            ->where('procurement_id', $id)->orderBy('created_at')->get()->getResultArray();

        return $this->ok($rows, [
            // The asker is NEVER named. An answer that identifies who asked lets
            // the rest of the field infer a competitor's approach.
            'note' => 'Questions are published anonymously and answers go to every purchaser at once.',
        ]);
    }

    public function answer(int $id, int $clarId)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }
        $answer = trim((string) ($this->body()['answer'] ?? ''));
        if ($answer === '') {
            return problem(422, 'validation_failed', 'An answer is required.');
        }

        db_connect()->table('clarifications')->where('id', $clarId)->where('procurement_id', $id)->update([
            'answer' => $answer, 'answered_by' => (int) $this->request->userId,
            'answered_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->ok(['answered' => true], ['published_to' => 'all purchasers, anonymously']);
    }

    public function addenda(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        return $this->ok(db_connect()->table('addenda')->where('procurement_id', $id)
            ->orderBy('number')->get()->getResultArray());
    }

    /**
     * An addendum is the ONLY way a published closing date moves. Editing the
     * date directly would leave no record it ever changed, which is exactly the
     * dispute an addendum exists to settle.
     */
    public function issueAddendum(int $id)
    {
        $proc = $this->procurement($id);
        if (! $proc) {
            return problem(404, 'not_found', 'No such tender.');
        }

        $in     = $this->body();
        $reason = trim((string) ($in['reason'] ?? ''));
        if ($reason === '') {
            return problem(422, 'validation_failed', 'An addendum must carry a reason.');
        }

        $newClosing = null;
        if (! empty($in['new_closing_at'])) {
            $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $in['new_closing_at']);
            if (! $d) {
                return problem(422, 'bad_date', 'New closing date must be Y-m-d H:i:s.');
            }
            // A date can be extended, never brought forward.
            if ($d->getTimestamp() <= strtotime((string) $proc['closing_at'])) {
                return problem(422, 'not_an_extension', 'A closing date can be extended but never brought forward.', [
                    'current_closing_at' => $proc['closing_at'],
                ]);
            }
            $newClosing = $d->format('Y-m-d H:i:s');
        }

        $db = db_connect();
        $db->transBegin();

        $number = (int) $db->table('addenda')->where('procurement_id', $id)->countAllResults() + 1;
        $db->table('addenda')->insert([
            'procurement_id' => $id, 'number' => $number, 'reason' => $reason,
            'new_closing_at' => $newClosing, 'issued_by' => (int) $this->request->userId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($newClosing) {
            // The extension and its numbered reason are written in ONE transaction.
            $db->table('notices')->where('id', $proc['notice_id'])->update(['closing_at' => $newClosing]);
        }

        $db->transCommit();

        service('eventLedger')->record('procurement', $id, 'addendum.issued', "Addendum #{$number} issued", [
            'number' => $number, 'reason' => $reason, 'new_closing_at' => $newClosing,
        ]);

        return $this->ok(['number' => $number, 'new_closing_at' => $newClosing], [], 201);
    }
}
