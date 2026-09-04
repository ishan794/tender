<?php

namespace App\Controllers\Api\V1\Authority;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Compliance\ComplianceEngine;
use CodeIgniter\HTTP\ResponseInterface;

/** Evaluate a procurement against the configured compliance rule matrix. */
class ComplianceController extends BaseApiController
{
    public function evaluate(): ResponseInterface
    {
        $in = $this->body();

        return $this->ok((new ComplianceEngine())->evaluate(
            (float) ($in['value'] ?? 0),
            isset($in['method']) ? (string) $in['method'] : null,
        ));
    }
}
