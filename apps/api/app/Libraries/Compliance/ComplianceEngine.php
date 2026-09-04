<?php

namespace App\Libraries\Compliance;

use Config\ProcurementRules;

/**
 * Evaluates a procurement's parameters against the configured rule matrix and
 * returns the requirements plus any warnings/errors. This is the single place
 * the rules live — controllers ASK the engine, they do not re-encode thresholds
 * inline, and the frontend is never the only enforcement layer.
 */
final class ComplianceEngine
{
    private ProcurementRules $rules;

    public function __construct(?ProcurementRules $rules = null)
    {
        $this->rules = $rules ?? config('ProcurementRules');
    }

    /**
     * @param float       $value  estimated value in LKR
     * @param string|null $method chosen procurement method (optional)
     */
    public function evaluate(float $value, ?string $method = null): array
    {
        $band = $this->bandFor($value);

        $bidSecurity = round($value * ($band['bid_security_pct'] / 100), 2);
        $warnings    = [];
        $errors      = [];

        if ($method !== null) {
            if (! in_array($method, $band['methods'], true)) {
                $errors[] = [
                    'code' => 'method_not_permitted',
                    'message' => "Method '{$method}' is not permitted for a procurement of this value.",
                    'allowed' => $band['methods'],
                ];
            }
            if (in_array($method, $this->rules->justificationRequired, true)) {
                $warnings[] = [
                    'code' => 'justification_required',
                    'message' => ucfirst($method) . ' procurement requires a documented written justification.',
                ];
            }
        }

        return [
            'value' => $value,
            'method' => $method,
            'requirements' => [
                'permitted_methods' => $band['methods'],
                'approval_authority' => $band['approval'],
                'committee' => $band['committee'],
                'bid_security_pct' => $band['bid_security_pct'],
                'bid_security_amount' => $bidSecurity,
                'standstill_days' => $band['standstill_days'],
                'mandatory_documents' => $band['mandatory_docs'],
            ],
            'warnings' => $warnings,
            'errors' => $errors,
            'compliant' => $errors === [],
        ];
    }

    private function bandFor(float $value): array
    {
        foreach ($this->rules->bands as $band) {
            if ($band['max'] === null || $value <= $band['max']) {
                return $band;
            }
        }

        return end($this->rules->bands);
    }
}
