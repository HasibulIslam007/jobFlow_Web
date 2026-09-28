<?php

namespace App\Services\OCR\Providers;

use App\Services\OCR\OCRException;

interface OCRProviderInterface
{
    /**
     * Extract plain text from an image at the given absolute file path.
     *
     *
     * @throws OCRException
     */
    public function extractText(string $absolutePath): string;
}
