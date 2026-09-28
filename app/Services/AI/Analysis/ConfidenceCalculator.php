<?php

namespace App\Services\AI\Analysis;

/**
 * Calculates a 0-1 confidence score from extraction completeness.
 *
 * Weights: title 20%, company 20%, location 15%, deadline 15%,
 * salary 10%, skills 10%, description 10%.
 */
class ConfidenceCalculator
{
    /**
     * @var array<string, float>
     */
    public const WEIGHTS = [
        'title' => 0.20,
        'company' => 0.20,
        'location' => 0.15,
        'deadline' => 0.15,
        'salary' => 0.10,
        'skills' => 0.10,
        'description' => 0.10,
    ];

    public function __construct(
        protected MissingFieldDetector $missingFieldDetector
    ) {}

    /**
     * @param  array<string, mixed>  $data  Parsed AI extraction payload.
     */
    public function calculate(array $data): float
    {
        $missing = $this->missingFieldDetector->detect($data);
        $missingLookup = array_flip($missing);

        $score = 0.0;

        foreach (self::WEIGHTS as $field => $weight) {
            if (! isset($missingLookup[$field])) {
                $score += $weight;
            }
        }

        return round($score, 3);
    }
}
