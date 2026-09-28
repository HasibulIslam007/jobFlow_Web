<?php

namespace App\Services\AI\Analysis;

/**
 * Detects which key job fields are missing from AI-extracted data.
 */
class MissingFieldDetector
{
    /**
     * Fields inspected for completeness.
     *
     * @var array<int, string>
     */
    public const FIELDS = [
        'title',
        'company',
        'location',
        'salary',
        'deadline',
        'skills',
        'description',
    ];

    /**
     * @param  array<string, mixed>  $data  Parsed AI extraction payload.
     * @return array<int, string> Missing field names.
     */
    public function detect(array $data): array
    {
        $missing = [];

        foreach (self::FIELDS as $field) {
            if ($this->isMissing($field, $data[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    protected function isMissing(string $field, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    return false;
                }

                if (! is_string($item) && ! empty($item)) {
                    return false;
                }
            }

            return true;
        }

        return empty($value);
    }
}
