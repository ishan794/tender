<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Four native procurements, one at each interesting stage, so the confidentiality
 * and separation-of-duties boundaries can actually be exercised:
 *
 *   #1  Published, sealed, five bids in, opening time already passed  → the ceremony
 *   #2  Awaiting approval, Rs. 92 M, created by the officer           → self-approval
 *   #3  Opened and under evaluation, three declarations on file       → the COI gate
 *   #4  Awarded, standstill expired                                   → ratings
 */
class ProcurementSeeder extends Seeder
{
    public function run(): void
    {
        if (defined('CI_ENVIRONMENT') && CI_ENVIRONMENT === 'production') {
            throw new \RuntimeException('SAFETY VIOLATION: ProcurementSeeder cannot be executed in production environment. Development fixtures are prohibited.');
        }

        $db   = $this->db;
        $now  = static fn (string $m) => date('Y-m-d H:i:s', strtotime($m));
        $slug = static fn (string $s) => trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $s)), '-');

        $cat  = array_column($db->table('categories')->get()->getResultArray(), 'id', 'slug');
        $dist = array_column($db->table('districts')->get()->getResultArray(), 'id', 'slug');
        $u    = array_column($db->table('users')->get()->getResultArray(), 'id', 'email');
        $orgs = array_column($db->table('organisations')->get()->getResultArray(), 'id', 'name');

        $rda      = $orgs['Road Development Authority'];
        $officer  = $u['officer@rda.lk'];
        $approver = $u['approver@rda.lk'];
        $opener2  = $u['opener2@rda.lk'];

        $bidders = [
            'Ranmuthu Engineering (Pvt) Ltd' => 266600000,
            'Sirisena & Sons Contractors'    => 281200000,
            'Nawaloka Civil Works'           => 259900000,
            'Eastern Highways (Pvt) Ltd'     => 294500000,
            'Sathosa Infrastructure Ltd'     => 272300000,
        ];

        $mk = static function (array $n, array $p) use ($db, $now, $slug, $rda, $officer) {
            $db->table('notices')->insert(array_merge([
                'kind' => 'tender', 'org_id' => $rda, 'sector' => 'government',
                'slug' => $slug($n['reference'] . '-' . $n['title']),
                'currency' => 'LKR', 'verified' => 1,
                'contact_officer' => 'Director (Procurement), Road Development Authority',
                'contact_email' => 'procurement@rda.lk',
                'created_at' => $now('-40 days'), 'updated_at' => $now('now'),
            ], $n));
            $nid = (int) $db->insertID();

            $db->table('procurements')->insert(array_merge([
                'org_id' => $rda, 'notice_id' => $nid, 'created_by' => $officer,
                'created_at' => $now('-40 days'), 'updated_at' => $now('now'),
            ], $p));

            return [$nid, (int) $db->insertID()];
        };

        // ---- #1 sealed, opening time passed, five bids waiting -------------
        [$n1, $p1] = $mk([
            'reference' => 'RDA/WP/2026/201',
            'title' => 'Construction of the Kelani right-bank flood protection bund, stage II',
            'summary' => 'The Road Development Authority invites sealed bids for the construction of '
                . 'a 3.4 km flood protection bund with associated drainage structures.',
            'description' => 'National Competitive Bidding. Bidders must hold CIDA grade C2 or above.',
            'category_id' => $cat['roads-bridges'], 'district_id' => $dist['colombo'],
            'estimated_value' => 310000000, 'document_fee' => 25000, 'bid_security' => 6200000,
            'published_at' => $now('-30 days'),
            'closing_at' => $now('-2 hours'),
            'opening_at' => $now('-1 hour'),
            'status' => 'published',
        ], [
            'stage_idx' => 2, 'submitted_by' => $officer,
            'approved_by' => $approver, 'approved_at' => $now('-31 days'),
            'published_by' => $officer, 'published_at' => $now('-30 days'),
        ]);

        $i = 0;
        foreach ($bidders as $name => $price) {
            $i++;
            $orgId   = $orgs[$name];
            $payload = json_encode(['bidder' => $name, 'price' => $price, 'lots' => ['A', 'B']]);
            $db->table('doc_purchases')->insert([
                'procurement_id' => $p1, 'buyer_org_id' => $orgId, 'amount' => 25000,
                'receipt_no' => 'DP-' . strtoupper(bin2hex(random_bytes(3))),
                'purchased_at' => $now('-20 days'), 'created_at' => $now('-20 days'),
            ]);
            $db->table('submissions')->insert([
                'procurement_id' => $p1, 'bidder_org_id' => $orgId, 'bidder_name' => $name,
                'reference' => sprintf('SUB-%d-%04d', $p1, $i),
                'total_price' => $price, 'has_security' => 1,
                'size_bytes' => 2400000 + $i * 13000,
                'content_hash' => hash('sha256', $payload),
                'status' => 'submitted', 'received_at' => $now('-' . (6 - $i) . ' hours'),
                'created_at' => $now('-1 day'), 'updated_at' => $now('-1 day'),
            ]);
        }

        $db->table('clarifications')->insert([
            'procurement_id' => $p1, 'asker_org_id' => $orgs['Ranmuthu Engineering (Pvt) Ltd'],
            'question' => 'Is the bid security acceptable as a bank guarantee from a foreign bank '
                . 'with a local correspondent?',
            'answer' => 'A bank guarantee must be issued by a commercial bank licensed by the Central '
                . 'Bank of Sri Lanka, or countersigned by one.',
            'answered_by' => $officer, 'answered_at' => $now('-18 days'),
            'created_at' => $now('-20 days'),
        ]);
        $db->table('clarifications')->insert([
            'procurement_id' => $p1, 'asker_org_id' => $orgs['Nawaloka Civil Works'],
            'question' => 'Please confirm whether the borrow pit royalty is to be included in the rates.',
            'created_at' => $now('-3 days'),
        ]);
        $db->table('addenda')->insert([
            'procurement_id' => $p1, 'number' => 1,
            'reason' => 'Extension of the closing date by fourteen days following the volume of '
                . 'clarifications received on the drainage schedule.',
            'new_closing_at' => $now('-2 hours'), 'issued_by' => $officer,
            'created_at' => $now('-16 days'),
        ]);

        // ---- #2 Rs. 92 M awaiting approval, created by the officer ---------
        $mk([
            'reference' => 'RDA/CP/2026/114',
            'title' => 'Rehabilitation of Colombo–Katunayake access road shoulders (native)',
            'summary' => 'Rehabilitation of shoulders and side drains over 11 km.',
            'category_id' => $cat['roads-bridges'], 'district_id' => $dist['colombo'],
            'estimated_value' => 92000000, 'document_fee' => 15000,
            'closing_at' => $now('+21 days'), 'opening_at' => $now('+21 days +1 hour'),
            'status' => 'draft',
        ], ['stage_idx' => 1, 'submitted_by' => $officer]);

        // ---- #3 opened, under evaluation, COI gate live --------------------
        [$n3, $p3] = $mk([
            'reference' => 'RDA/SP/2026/121',
            'title' => 'Widening and resurfacing of Galle–Udugama road, sections 4–7 (native)',
            'summary' => 'Widening to two lanes with resurfacing over 18 km.',
            'category_id' => $cat['roads-bridges'], 'district_id' => $dist['galle'],
            'estimated_value' => 265000000, 'document_fee' => 20000,
            'published_at' => $now('-60 days'),
            'closing_at' => $now('-10 days'), 'opening_at' => $now('-10 days +1 hour'),
            'status' => 'published',
        ], [
            'stage_idx' => 4, 'submitted_by' => $officer,
            'approved_by' => $approver, 'approved_at' => $now('-61 days'),
            'published_by' => $officer, 'published_at' => $now('-60 days'),
            'opened_by_a' => $officer, 'opened_by_b' => $opener2,
            'opening_started_at' => $now('-10 days +1 hour'), 'opened_at' => $now('-10 days +70 minutes'),
        ]);

        $j = 0;
        foreach (['Ranmuthu Engineering (Pvt) Ltd' => 251000000,
                  'Sathosa Infrastructure Ltd' => 244700000,
                  'Sirisena & Sons Contractors' => 263800000] as $name => $price) {
            $j++;
            $db->table('doc_purchases')->insert([
                'procurement_id' => $p3, 'buyer_org_id' => $orgs[$name], 'amount' => 20000,
                'receipt_no' => 'DP-' . strtoupper(bin2hex(random_bytes(3))),
                'purchased_at' => $now('-40 days'), 'created_at' => $now('-40 days'),
            ]);
            $db->table('submissions')->insert([
                'procurement_id' => $p3, 'bidder_org_id' => $orgs[$name], 'bidder_name' => $name,
                'reference' => sprintf('SUB-%d-%04d', $p3, $j),
                'total_price' => $price, 'has_security' => 1, 'size_bytes' => 1900000 + $j * 22000,
                'content_hash' => hash('sha256', $name . $price),
                'status' => 'opened', 'received_at' => $now('-11 days'),
                'created_at' => $now('-11 days'), 'updated_at' => $now('-10 days'),
            ]);
        }

        foreach ([['Compliance with the technical specification', 'pass_fail', 0],
                  ['Evaluated price', 'weighted', 60],
                  ['Similar experience in the last five years', 'weighted', 25],
                  ['Proposed methodology and programme', 'weighted', 15]] as [$label, $type, $w]) {
            $db->table('eval_criteria')->insert([
                'procurement_id' => $p3, 'label' => $label, 'type' => $type,
                'weight' => $w, 'max_score' => 100, 'created_at' => $now('-9 days'),
            ]);
        }

        // Cleared, conflicted — and one evaluator deliberately left undeclared.
        $db->table('coi_declarations')->insert([
            'procurement_id' => $p3, 'user_id' => $u['evaluator@rda.lk'], 'has_conflict' => 0,
            'statement' => 'No interest, financial or personal, in any bidder on this tender.',
            'created_at' => $now('-9 days'),
        ]);
        $db->table('coi_declarations')->insert([
            'procurement_id' => $p3, 'user_id' => $u['conflicted@rda.lk'], 'has_conflict' => 1,
            'statement' => 'A close relative is a director of one of the bidding companies.',
            'created_at' => $now('-9 days'),
        ]);

        // ---- #4 awarded, standstill expired, ready to rate -----------------
        [$n4, $p4] = $mk([
            'reference' => 'RDA/NW/2025/318',
            'title' => 'Resurfacing of the Kurunegala–Dambulla road, sections 1–3',
            'summary' => 'Completed procurement retained for the award history.',
            'category_id' => $cat['roads-bridges'], 'district_id' => $dist['kurunegala'],
            'estimated_value' => 188000000,
            'published_at' => $now('-180 days'),
            'closing_at' => $now('-150 days'), 'opening_at' => $now('-150 days +1 hour'),
            'status' => 'published',
        ], [
            'stage_idx' => 6, 'submitted_by' => $officer,
            'approved_by' => $approver, 'approved_at' => $now('-181 days'),
            'published_by' => $officer, 'published_at' => $now('-180 days'),
            'opened_by_a' => $officer, 'opened_by_b' => $opener2,
            'opened_at' => $now('-150 days +70 minutes'),
        ]);

        $winner = $orgs['Nawaloka Civil Works'];
        $db->table('doc_purchases')->insert([
            'procurement_id' => $p4, 'buyer_org_id' => $winner, 'amount' => 18000,
            'receipt_no' => 'DP-ARCHIVE', 'purchased_at' => $now('-170 days'), 'created_at' => $now('-170 days'),
        ]);
        $db->table('submissions')->insert([
            'procurement_id' => $p4, 'bidder_org_id' => $winner, 'bidder_name' => 'Nawaloka Civil Works',
            'reference' => sprintf('SUB-%d-0001', $p4), 'total_price' => 179400000, 'has_security' => 1,
            'size_bytes' => 2100000, 'content_hash' => hash('sha256', 'archive'),
            'status' => 'opened', 'received_at' => $now('-151 days'),
            'created_at' => $now('-151 days'), 'updated_at' => $now('-150 days'),
        ]);
        $subId = (int) $db->insertID();

        $db->table('awards')->insert([
            'procurement_id' => $p4, 'submission_id' => $subId, 'supplier_org_id' => $winner,
            'amount' => 179400000, 'committee_ref' => 'DPC/2026/MIN/44',
            'awarded_by' => $approver, 'awarded_at' => $now('-140 days'),
            'standstill_until' => $now('-133 days'), 'created_at' => $now('-140 days'),
        ]);

        // Bidder-side pipeline and vault, on live rows rather than fixtures.
        $paid = $orgs['Lanka Constructions (Pvt) Ltd'];
        foreach ($db->table('notices')->where('status', 'published')->where('kind', 'tender')
                     ->limit(4)->get()->getResultArray() as $k => $n) {
            $db->table('bids')->insert([
                'org_id' => $paid, 'notice_id' => (int) $n['id'],
                'stage' => ['watching', 'preparing', 'ready', 'submitted'][$k],
                'checklist_total' => 6, 'checklist_ready' => [1, 3, 6, 6][$k],
                'created_at' => $now('-10 days'), 'updated_at' => $now('now'),
            ]);
        }
        foreach ([['Business registration certificate', 'registration', '+9 months'],
                  ['CIDA registration C2', 'cida', '+2 months'],
                  ['VAT registration', 'tax', '+14 months'],
                  ['ICTAD equipment schedule', 'capability', '-10 days']] as [$n, $k, $exp]) {
            $db->table('document_assets')->insert([
                'org_id' => $paid, 'name' => $n, 'kind' => $k,
                'expires_at' => date('Y-m-d', strtotime($exp)),
                'created_at' => $now('-100 days'), 'updated_at' => $now('now'),
            ]);
        }

        $db->table('alert_profiles')->insert([
            'org_id' => $paid, 'user_id' => array_column($db->table('users')->where('org_id', $paid)->get()->getResultArray(), 'id')[0],
            'name' => 'Civil works — Western', 'kinds' => 'tender',
            'category_slugs' => 'roads-bridges,buildings', 'district_slugs' => 'colombo,gampaha,kalutara',
            'min_value' => 10000000, 'channels' => 'inapp,email', 'active' => 1,
            'created_at' => $now('-60 days'), 'updated_at' => $now('now'),
        ]);
        $db->table('alert_profiles')->insert([
            'org_id' => $paid, 'user_id' => array_column($db->table('users')->where('org_id', $paid)->get()->getResultArray(), 'id')[0],
            'name' => 'Any water or drainage work', 'kinds' => 'tender',
            'category_slugs' => 'water-drainage', 'channels' => 'inapp', 'active' => 1,
            'created_at' => $now('-40 days'), 'updated_at' => $now('now'),
        ]);

        // A partner key, so the feed can be exercised. The plaintext is printed
        // by the seeder and never stored.
        $key = 'th_live_' . bin2hex(random_bytes(16));
        $db->table('api_keys')->insert([
            'org_id' => $orgs['Sri Lanka Telecom PLC'] ?? $rda, 'label' => 'Development partner key',
            'prefix' => substr($key, 0, 12), 'key_hash' => hash('sha256', $key),
            'daily_quota' => 1000, 'used_today' => 0, 'quota_date' => date('Y-m-d'),
            'created_at' => $now('now'), 'updated_at' => $now('now'),
        ]);
        file_put_contents(WRITEPATH . 'dev-partner-key.txt', $key);
        \CodeIgniter\CLI\CLI::write('Partner API key (dev): ' . $key, 'yellow');
    }
}
