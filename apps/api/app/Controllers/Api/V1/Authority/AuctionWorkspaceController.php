<?php

namespace App\Controllers\Api\V1\Authority;

class AuctionWorkspaceController extends WorkspaceBase
{
    public const ASSET_CLASSES = ['land', 'house', 'commercial', 'vehicle', 'machinery', 'goods'];
    public const METHODS = ['parate', 'foreclosure', 'recovery', 'disposal'];

    public function index()
    {
        return $this->ok(db_connect()->table('auction_lots')
            ->select('auction_lots.*, notices.title, notices.reference, notices.slug, notices.closing_at, notices.status')
            ->join('notices', 'notices.id = auction_lots.notice_id')
            ->where('notices.org_id', (int) $this->request->orgId)
            ->orderBy('notices.closing_at', 'ASC')->get()->getResultArray(), [
                'asset_classes' => self::ASSET_CLASSES, 'methods' => self::METHODS,
                'custody' => 'TenderHub never holds any part of a purchase price. Taking custody '
                    . 'of bidder money would make this a payment institution under the Payment '
                    . 'and Settlement Systems Act No. 28 of 2005.',
            ]);
    }

    public function create()
    {
        $in = $this->body();
        foreach (['title', 'reference', 'closing_at', 'asset_class', 'method'] as $r) {
            if (empty($in[$r])) {
                return problem(422, 'validation_failed', str_replace('_', ' ', $r) . ' is required.');
            }
        }
        if (! in_array($in['asset_class'], self::ASSET_CLASSES, true)) {
            return problem(422, 'bad_asset_class', 'Unknown asset class.', ['allowed' => self::ASSET_CLASSES]);
        }
        if (! in_array($in['method'], self::METHODS, true)) {
            return problem(422, 'bad_method', 'Unknown auction method.', ['allowed' => self::METHODS]);
        }
        if (strtotime($in['closing_at']) < time()) {
            return problem(422, 'closing_in_past', 'The closing date must be in the future.');
        }
        if (isset($in['reserve']) && (float) $in['reserve'] < 0) {
            return problem(422, 'bad_reserve', 'Reserve price cannot be negative.');
        }
        if (isset($in['deposit_pct']) && ((float) $in['deposit_pct'] <= 0 || (float) $in['deposit_pct'] > 100)) {
            return problem(422, 'bad_deposit_pct', 'Deposit percentage must be between 0.01 and 100.');
        }

        $db = db_connect();
        $db->transBegin();

        // For an auction the closing time IS the auction time — there is no
        // separate sealed submission to close beforehand.
        $db->table('notices')->insert([
            'kind' => 'auction', 'reference' => $in['reference'],
            'slug' => url_title($in['reference'] . '-' . $in['title'], '-', true),
            'title' => $in['title'], 'summary' => $in['summary'] ?? null,
            'org_id' => (int) $this->request->orgId,
            'district_id' => $in['district_id'] ?? null, 'category_id' => $in['category_id'] ?? null,
            'sector' => $in['sector'] ?? 'private',
            'estimated_value' => $in['reserve'] ?? null,
            'closing_at' => $in['closing_at'], 'opening_at' => $in['closing_at'],
            'status' => 'draft',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $noticeId = $db->insertID();

        $db->table('auction_lots')->insert([
            'notice_id' => $noticeId, 'lot_no' => $in['lot_no'] ?? '1',
            'asset_class' => $in['asset_class'], 'method' => $in['method'],
            'reserve' => $in['reserve'] ?? null, 'deposit_pct' => $in['deposit_pct'] ?? 10,
            'venue' => $in['venue'] ?? null, 'auctioneer' => $in['auctioneer'] ?? null,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $lotId = $db->insertID();
        $db->transCommit();

        service('eventLedger')->record('auction', (int) $lotId, 'auction.created', "Auction lot created: {$in['title']}", [
            'notice_id'   => (int) $noticeId,
            'asset_class' => $in['asset_class'],
            'method'      => $in['method'],
            'reserve'     => isset($in['reserve']) ? (float) $in['reserve'] : null,
        ]);

        return $this->ok(['id' => (int) $lotId, 'notice_id' => (int) $noticeId], [], 201);
    }

    public function publish(int $lotId)
    {
        $db  = db_connect();
        $lot = $db->table('auction_lots')
            ->select('auction_lots.*, notices.org_id, notices.closing_at, notices.status')
            ->join('notices', 'notices.id = auction_lots.notice_id')
            ->where('auction_lots.id', $lotId)->get()->getFirstRow('array');

        if (! $lot || (int) $lot['org_id'] !== (int) $this->request->orgId) {
            return problem(404, 'not_found', 'No such lot.');
        }
        if (($lot['status'] ?? '') === 'published') {
            return problem(409, 'already_published', 'This auction is already published.');
        }
        if (! $lot['closing_at']) {
            return problem(409, 'no_closing_date', 'An auction cannot be published without a date.');
        }

        $db->table('notices')->where('id', $lot['notice_id'])
            ->update(['status' => 'published', 'published_at' => date('Y-m-d H:i:s')]);

        service('eventLedger')->record('auction', (int) $lotId, 'auction.published', 'Auction lot published', [
            'notice_id' => (int) $lot['notice_id'],
        ]);

        return $this->ok(['published' => true]);
    }

    /** A withdrawn or postponed lot is RECORDED, never deleted — someone
     *  travelled to a sale that did not happen, and they are owed the reason. */
    public function result(int $lotId)
    {
        $db  = db_connect();
        $lot = $db->table('auction_lots')
            ->select('auction_lots.*, notices.org_id, notices.status')
            ->join('notices', 'notices.id = auction_lots.notice_id')
            ->where('auction_lots.id', $lotId)->get()->getFirstRow('array');

        if (! $lot || (int) $lot['org_id'] !== (int) $this->request->orgId) {
            return problem(404, 'not_found', 'No such lot.');
        }
        if (($lot['status'] ?? '') !== 'published') {
            return problem(409, 'not_published', 'Cannot record a result on an unpublished auction.');
        }
        if (($lot['result'] ?? '') === 'sold') {
            return problem(409, 'already_sold', 'This lot has already been recorded as sold.');
        }

        $in     = $this->body();
        $result = $in['result'] ?? '';
        if (! in_array($result, ['sold', 'unsold', 'withdrawn', 'postponed'], true)) {
            return problem(422, 'bad_result', 'Result must be sold, unsold, withdrawn or postponed.');
        }

        $hammer = $result === 'sold' ? (float) ($in['hammer_price'] ?? 0) : null;

        if ($result === 'sold' && $lot['reserve'] !== null && $hammer < (float) $lot['reserve']) {
            return problem(409, 'below_reserve', 'The hammer price is below the published reserve.', [
                'reserve' => (float) $lot['reserve'], 'hammer_price' => $hammer,
                'remedy' => 'Record the lot as unsold, or publish a revised reserve before the sale.',
            ]);
        }

        if (in_array($result, ['withdrawn', 'postponed'], true) && empty($in['result_note'])) {
            return problem(422, 'reason_required', 'A withdrawn or postponed lot needs a reason.');
        }

        $db->table('auction_lots')->where('id', $lotId)->update([
            'result' => $result, 'hammer_price' => $hammer,
            'result_note' => $in['result_note'] ?? null, 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        service('eventLedger')->record('auction', (int) $lotId, 'auction.result_recorded', "Auction result recorded: {$result}", [
            'result'       => $result,
            'hammer_price' => $hammer,
        ]);

        return $this->ok(['result' => $result, 'hammer_price' => $hammer]);
    }
}
