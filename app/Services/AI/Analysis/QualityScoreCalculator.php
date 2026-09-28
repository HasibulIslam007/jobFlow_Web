<?php

namespace App\Services\AI\Analysis;

/**
 * Calculates a 0-100 quality score from extraction completeness.
 *
 * Reuses the confidence weights and scales the result to a percentage.
 */
class QualityScoreCalculator
{
    public function __construct(
        protected ConfidenceCalculator $confidenceCalculator
    ) {}

    /**
     * @param  array<string, mixed>  $data  Parsed AI extraction payload.
     */
    public function calculate(array $data): int
    {
        return (int) round($this->confidenceCalculator->calculate($data) * 100);
    }
}
