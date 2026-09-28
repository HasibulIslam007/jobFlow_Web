<?php

namespace App\Services\AI\DTOs;

use JsonException;

class AIResponseDTO
{
    /**
     * @param  array<string, mixed>|null  $parsed
     * @param  array<string, string>  $validationErrors
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $rawResponse,
        public readonly ?array $parsed = null,
        public readonly array $validationErrors = [],
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly array $meta = [],
    ) {}

    /**
     * Parse raw string response and build DTO.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function fromRaw(
        string $raw,
        string $provider = '',
        string $model = '',
        array $meta = []
    ): self {
        $clean = trim($raw);

        // Strip markdown code fences if present (e.g. ```json ... ``` or ``` ...)
        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
            $clean = preg_replace('/\s*```$/', '', $clean);
            $clean = trim((string) $clean);
        }

        $parsed = null;
        $errors = [];

        try {
            $decoded = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $parsed = $decoded;
            } else {
                $errors['json'] = 'JSON payload must decode to an associative array or list.';
            }
        } catch (JsonException $e) {
            $errors['json'] = 'Invalid JSON: '.$e->getMessage();
        }

        return new self(
            rawResponse: $raw,
            parsed: $parsed,
            validationErrors: $errors,
            provider: $provider,
            model: $model,
            meta: $meta,
        );
    }

    /**
     * Determine if JSON was parsed without errors.
     */
    public function isValid(): bool
    {
        return empty($this->validationErrors) && $this->parsed !== null;
    }

    /**
     * Get parsed payload or empty array.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->parsed ?? [];
    }
}
