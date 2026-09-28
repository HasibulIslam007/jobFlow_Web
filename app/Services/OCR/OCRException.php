<?php

namespace App\Services\OCR;

use Exception;

class OCRException extends Exception
{
    public static function fileNotFound(string $path): self
    {
        return new self("Image file not found at path: [{$path}].");
    }

    public static function emptyText(string $path): self
    {
        return new self("No readable text found in image: [{$path}]. The image may be unreadable, blank, or low quality.");
    }

    public static function corrupted(string $path, string $error): self
    {
        return new self("Failed to process corrupted image at [{$path}]: {$error}");
    }

    public static function failed(string $message): self
    {
        return new self("OCR extraction failed: {$message}");
    }
}
