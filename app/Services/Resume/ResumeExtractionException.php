<?php

namespace App\Services\Resume;

use Throwable;

class ResumeExtractionException extends Throwable
{
    public static function fileNotFound(string $filePath): self
    {
        return new self("Resume file not found [{$filePath}].");
    }

    public static function unsupported(string $fileType): self
    {
        return new self("Unsupported resume file type [{$fileType}]. Supported types: pdf.");
    }

    public static function failed(string $reason, ?Throwable $previous = null): self
    {
        return new self("Resume text extraction failed: {$reason}", 0, $previous);
    }
}
