<?php

namespace App\Services\PDF;

use Exception;

class PdfExtractionException extends Exception
{
    public static function fileNotFound(string $path): self
    {
        return new self("PDF file not found at path: [{$path}].");
    }

    public static function emptyText(string $path): self
    {
        return new self("No readable text found in PDF: [{$path}]. It may be scanned or empty.");
    }

    public static function corrupted(string $path, string $error): self
    {
        return new self("Failed to parse corrupted PDF at [{$path}]: {$error}");
    }

    public static function failed(string $message): self
    {
        return new self("PDF extraction failed: {$message}");
    }
}
