<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Procurement compliance rules as a CONFIG MATRIX, not a database table — value
 * bands map to the method, approval authority, committee, bid security and
 * mandatory documents each procurement requires. Sales/policy can adjust these
 * without a migration. Figures are indicative of the NPC guideline structure
 * and are the org-configurable defaults; the authoritative current thresholds
 * should be confirmed against the live NPC Procurement Manual + supplements.
 */
class ProcurementRules extends BaseConfig
{
    /**
     * Ordered ascending by `max` (LKR). The first band whose `max` the value
     * does not exceed applies; the last band (max = null) is the ceiling.
     *
     * @var list<array<string,mixed>>
     */
    public array $bands = [
        [
            'max' => 500_000,
            'methods' => ['shopping', 'rfq', 'direct'],
            'approval' => 'procurement_officer',
            'committee' => null,
            'bid_security_pct' => 0.0,
            'standstill_days' => 0,
            'mandatory_docs' => ['specification'],
        ],
        [
            'max' => 5_000_000,
            'methods' => ['rfq', 'limited', 'open'],
            'approval' => 'head_of_department',
            'committee' => 'technical_evaluation_committee',
            'bid_security_pct' => 1.0,
            'standstill_days' => 7,
            'mandatory_docs' => ['specification', 'boq', 'bidding_document'],
        ],
        [
            'max' => 50_000_000,
            'methods' => ['open', 'limited', 'two_stage'],
            'approval' => 'department_procurement_committee',
            'committee' => 'technical_evaluation_committee',
            'bid_security_pct' => 2.0,
            'standstill_days' => 7,
            'mandatory_docs' => ['specification', 'boq', 'bidding_document', 'draft_contract'],
        ],
        [
            'max' => null,
            'methods' => ['open', 'two_stage', 'framework'],
            'approval' => 'cabinet_appointed_procurement_committee',
            'committee' => 'cabinet_appointed_technical_evaluation_committee',
            'bid_security_pct' => 2.0,
            'standstill_days' => 14,
            'mandatory_docs' => ['specification', 'boq', 'bidding_document', 'draft_contract', 'cabinet_approval'],
        ],
    ];

    /** Methods that always require a written justification, whatever the value. */
    public array $justificationRequired = ['direct', 'limited'];
}
