<?php

namespace App\Services\JobCapture\DTOs;

use App\Models\Job;

class CaptureResultDTO
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?Job $job = null,
        public readonly ?string $errorMessage = null,
        public readonly array $metadata = []
    ) {}

    public static function successful(Job $job, array $metadata = []): self
    {
        return new self(
            success: true,
            job: $job,
            errorMessage: null,
            metadata: $metadata
        );
    }

    public static function failed(string $errorMessage, array $metadata = []): self
    {
        return new self(
            success: false,
            job: null,
            errorMessage: $errorMessage,
            metadata: $metadata
        );
    }
}
