<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (defined('CI_ENVIRONMENT') && CI_ENVIRONMENT === 'production') {
            throw new \RuntimeException('SAFETY VIOLATION: DatabaseSeeder cannot be executed in production environment. Truncation and development seed credentials are prohibited.');
        }

        $db = $this->db;

        $truncateTables = [
            'factories','password_resets','email_verifications','debarred_suppliers','addenda','alert_profiles',
            'api_keys','auction_lots','bid_seals','bids','clarifications','coi_declarations','complaints',
            'contract_invoices','contract_milestones','contract_variations','contracts','data_requests',
            'doc_purchases','document_assets','document_downloads','document_versions','eval_criteria',
            'eval_scores','event_ledger','invitations','kyc_submissions','legal_holds','notice_documents',
            'notification_deliveries','notifications','orders','payments','procurement_plans','ratings',
            'awards','refresh_tokens','security_events','signatures','submissions','tco_assessments',
            'tender_keys','procurements','notices','authorities','feed_sources','categories',
            'users','webhook_deliveries','webhooks','organisations','districts','provinces'
        ];

        foreach ($truncateTables as $t) {
            $db->table($t)->truncate();
        }
        // Ids shift on every re-seed and that silently repointed saved profiles
        // at the wrong category. Profiles match on slugs now, and we reset the
        // sequence so fixtures are reproducible.
        if ($db->DBDriver === 'SQLite3') {
            $db->query("DELETE FROM sqlite_sequence");
        }

        $now = static fn (string $mod = 'now') => date('Y-m-d H:i:s', strtotime($mod));
        $slug = static fn (string $s) => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $s));

        // ------------------------------------------------------- geography
        $provinces = ['Western','Central','Southern','Northern','Eastern','North Western','North Central','Uva','Sabaragamuwa'];
        foreach ($provinces as $p) {
            $db->table('provinces')->insert(['name' => $p, 'slug' => $slug($p)]);
        }

        $districts = [
            'Western' => ['Colombo','Gampaha','Kalutara'],
            'Central' => ['Kandy','Matale','Nuwara Eliya'],
            'Southern' => ['Galle','Matara','Hambantota'],
            'Northern' => ['Jaffna','Kilinochchi','Mannar','Vavuniya','Mullaitivu'],
            'Eastern' => ['Batticaloa','Ampara','Trincomalee'],
            'North Western' => ['Kurunegala','Puttalam'],
            'North Central' => ['Anuradhapura','Polonnaruwa'],
            'Uva' => ['Badulla','Monaragala'],
            'Sabaragamuwa' => ['Ratnapura','Kegalle'],
        ];
        $distId = [];
        foreach ($districts as $prov => $list) {
            $pid = (int) $db->table('provinces')->where('slug', $slug($prov))->get()->getFirstRow('array')['id'];
            foreach ($list as $d) {
                $db->table('districts')->insert(['province_id' => $pid, 'name' => $d, 'slug' => $slug($d)]);
                $distId[$d] = (int) $db->insertID();
            }
        }

        // ------------------------------------------------------ categories
        $tree = [
            'Construction & Civil Works' => ['Buildings','Roads & Bridges','Water & Drainage','Electrical Installation'],
            'Goods & Supplies' => ['Medical Supplies','Office Equipment','Furniture','Food & Rations'],
            'Services' => ['Consultancy','Security Services','Cleaning & Janitorial','Transport & Logistics'],
            'ICT' => ['Software & Systems','Hardware','Networking'],
            'Vehicles & Machinery' => ['Vehicles','Heavy Machinery','Spare Parts'],
            'Land & Property' => ['Land Sale','Building Sale','Lease'],
        ];
        $catId = [];
        foreach ($tree as $parent => $kids) {
            $db->table('categories')->insert(['parent_id' => null, 'name' => $parent, 'slug' => $slug($parent)]);
            $pid = (int) $db->insertID();
            $catId[$parent] = $pid;
            foreach ($kids as $k) {
                $db->table('categories')->insert(['parent_id' => $pid, 'name' => $k, 'slug' => $slug($k)]);
                $catId[$k] = (int) $db->insertID();
            }
        }

        // ----------------------------------------------------- authorities
        $authorities = [
            ['Road Development Authority', 'government'],
            ['Ceylon Electricity Board', 'government'],
            ['National Water Supply & Drainage Board', 'government'],
            ['Ministry of Health', 'government'],
            ['Sri Lanka Ports Authority', 'government'],
            ['Sri Lanka Railways', 'government'],
            ['University of Colombo', 'government'],
            ['Urban Development Authority', 'government'],
            ['Colombo Municipal Council', 'government'],
            ['Bank of Ceylon', 'government'],
            ['People\'s Bank', 'government'],
            ['Hatton National Bank PLC', 'private'],
            ['Commercial Bank of Ceylon PLC', 'private'],
            ['Sampath Bank PLC', 'private'],
            ['Sri Lanka Telecom PLC', 'private'],
            ['Ceylon Petroleum Corporation', 'government'],
            ['Mahaweli Authority of Sri Lanka', 'government'],
            ['Asian Development Bank Project Office', 'donor'],
            ['World Bank Sri Lanka', 'donor'],
            ['Department of Irrigation', 'government'],
        ];
        $authId = [];
        foreach ($authorities as [$n, $s]) {
            $db->table('authorities')->insert(['name' => $n, 'slug' => $slug($n), 'sector' => $s]);
            $authId[$n] = (int) $db->insertID();
        }

        // ---------------------------------------------------- feed sources
        foreach ([
            ['dgmarket.lk Government Gazette', 'feed', 42.5],
            ['Road Development Authority portal', 'scrape', 8.0],
            ['Daily News classifieds', 'scrape', 31.0],
            ['Ceylon Electricity Board procurement', 'mailbox', 6.25],
            ['Bank auction notices — Sunday Times', 'scrape', 18.75],
        ] as [$n, $mode, $baseline]) {
            $db->table('feed_sources')->insert([
                'name' => $n, 'slug' => $slug($n), 'mode' => $mode,
                'weekly_baseline' => $baseline,
                'last_fetch_at' => date('Y-m-d H:i:s', strtotime('-' . random_int(20, 400) . ' minutes')),
                'active' => 1, 'created_at' => $now(), 'updated_at' => $now(),
            ]);
        }

        // ------------------------------------------------------------ orgs
        $mkOrg = static function (array $o) use ($db, $now, $slug) {
            $db->table('organisations')->insert(array_merge([
                'slug' => $slug($o['name']) . '-' . bin2hex(random_bytes(2)),
                'type' => 'bidder', 'plan' => 'free', 'sub_status' => 'none', 'seats' => 3,
                'verify_state' => 'verified', 'verified_at' => $now(),
                'approval_threshold' => 50000000, 'standstill_days' => 7,
                'created_at' => $now('-6 months'), 'updated_at' => $now(),
            ], $o));

            return (int) $db->insertID();
        };
        $mkUser = static function (array $u) use ($db, $now) {
            $db->table('users')->insert(array_merge([
                'password_hash' => password_hash('Password123', PASSWORD_DEFAULT),
                'role' => 'owner', 'user_group' => 'bidder', 'status' => 'active',
                'free_views' => 0, 'created_at' => $now('-6 months'), 'updated_at' => $now(),
            ], $u));

            return (int) $db->insertID();
        };

        $staffOrg = $mkOrg(['name' => 'TenderHub', 'type' => 'staff', 'plan' => 'staff', 'sub_status' => 'active']);
        $mkUser(['org_id' => $staffOrg, 'name' => 'Nimal Perera', 'email' => 'staff@tenderhub.lk',
                 'phone' => '+94770000001', 'role' => 'owner', 'user_group' => 'staff']);

        // Buying organisation with a full committee.
        $rdaOrg = $mkOrg(['name' => 'Road Development Authority', 'type' => 'company', 'plan' => 'publish',
                          'sub_status' => 'active', 'district_id' => $distId['Colombo'], 'seats' => 10,
                          'approval_threshold' => 50000000]);
        $officer   = $mkUser(['org_id' => $rdaOrg, 'name' => 'S. Wickramasinghe', 'email' => 'officer@rda.lk',   'role' => 'officer',   'user_group' => 'company']);
        $approver  = $mkUser(['org_id' => $rdaOrg, 'name' => 'D. Jayasuriya',     'email' => 'approver@rda.lk',  'role' => 'approver',  'user_group' => 'company']);
        $opener2   = $mkUser(['org_id' => $rdaOrg, 'name' => 'M. Fernando',       'email' => 'opener2@rda.lk',   'role' => 'officer',   'user_group' => 'company']);
        $evalClear = $mkUser(['org_id' => $rdaOrg, 'name' => 'K. Rajapakse',      'email' => 'evaluator@rda.lk', 'role' => 'evaluator', 'user_group' => 'company']);
        $evalConf  = $mkUser(['org_id' => $rdaOrg, 'name' => 'T. Silva',          'email' => 'conflicted@rda.lk','role' => 'evaluator', 'user_group' => 'company']);
        $mkUser(['org_id' => $rdaOrg, 'name' => 'A. Bandara', 'email' => 'undeclared@rda.lk', 'role' => 'evaluator', 'user_group' => 'company']);
        $mkUser(['org_id' => $rdaOrg, 'name' => 'R. Gunawardena', 'email' => 'owner@rda.lk', 'role' => 'owner', 'user_group' => 'company']);

        $cebOrg = $mkOrg(['name' => 'Ceylon Electricity Board', 'type' => 'company', 'plan' => 'publish',
                          'sub_status' => 'active', 'district_id' => $distId['Colombo'], 'seats' => 10]);
        $mkUser(['org_id' => $cebOrg, 'name' => 'P. Dissanayake', 'email' => 'officer@ceb.lk', 'role' => 'owner', 'user_group' => 'company']);

        // Bidders — one paid, one free, plus the five that will submit.
        $paidOrg = $mkOrg(['name' => 'Lanka构 Constructions', 'type' => 'bidder', 'plan' => 'business',
                           'sub_status' => 'active', 'renews_at' => $now('+8 months'),
                           'district_id' => $distId['Gampaha'], 'cida_grade' => 'C2']);
        $db->table('organisations')->where('id', $paidOrg)->update(['name' => 'Lanka Constructions (Pvt) Ltd']);
        $mkUser(['org_id' => $paidOrg, 'name' => 'Sunil Alwis', 'email' => 'paid@bidder.lk', 'phone' => '+94770000010']);

        $freeOrg = $mkOrg(['name' => 'Negombo Builders', 'type' => 'bidder', 'district_id' => $distId['Gampaha'], 'cida_grade' => 'C5']);
        $mkUser(['org_id' => $freeOrg, 'name' => 'Ruwan Peiris', 'email' => 'free@bidder.lk', 'phone' => '+94770000011', 'free_views' => 2]);

        $bidderOrgs = [];
        foreach ([
            ['Ranmuthu Engineering (Pvt) Ltd', 'C1', 'Colombo'],
            ['Sirisena & Sons Contractors',    'C2', 'Kandy'],
            ['Nawaloka Civil Works',           'C1', 'Colombo'],
            ['Eastern Highways (Pvt) Ltd',     'C3', 'Batticaloa'],
            ['Sathosa Infrastructure Ltd',     'C2', 'Galle'],
        ] as [$n, $g, $d]) {
            $id = $mkOrg(['name' => $n, 'type' => 'bidder', 'plan' => 'business', 'sub_status' => 'active',
                          'renews_at' => $now('+6 months'), 'cida_grade' => $g, 'district_id' => $distId[$d]]);
            $mkUser(['org_id' => $id, 'name' => explode(' ', $n)[0] . ' Manager',
                     'email' => strtolower(explode(' ', $n)[0]) . '@bidder.lk']);
            $bidderOrgs[$n] = $id;
        }

        // A pending bank-transfer claim sitting in the staff queue.
        $pendingOrg = $mkOrg(['name' => 'Kandy Metal Works', 'type' => 'bidder', 'sub_status' => 'pending',
                              'district_id' => $distId['Kandy'], 'cida_grade' => 'C4']);
        $pendingUser = $mkUser(['org_id' => $pendingOrg, 'name' => 'Chaminda Herath', 'email' => 'pending@bidder.lk']);
        $db->table('payments')->insert([
            'org_id' => $pendingOrg, 'user_id' => $pendingUser, 'plan' => 'business', 'term' => 'annual',
            'amount' => 24000, 'method' => 'bank_transfer', 'bank' => 'Commercial Bank',
            'slip_ref' => 'CB-8842119', 'paid_on' => date('Y-m-d', strtotime('-2 days')),
            'channel' => 'whatsapp', 'state' => 'claimed',
            'created_at' => $now('-30 hours'), 'updated_at' => $now('-30 hours'),
        ]);

        $this->call(MultilingualReferenceSeeder::class);
        $this->call(CatalogueSeeder::class);
        $this->call(ProcurementSeeder::class);
    }
}
