<?php

namespace App\Services\PDF;

use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Throwable;

class PdfTextExtractor
{
    public function __construct(
        protected ?Parser $parser = null
    ) {
        $this->parser = $parser ?? new Parser;
    }

    /**
     * Extract plain text from a stored PDF file.
     *
     * @param  string  $filePath  Storage relative path or absolute file path
     * @param  string|null  $disk  Filesystem disk (null defaults to configured default disk)
     *
     * @throws PdfExtractionException
     */
    public function extract(string $filePath, ?string $disk = null): string
    {
        $realPath = $this->resolvePath($filePath, $disk);

        if (! file_exists($realPath) || ! is_readable($realPath)) {
            throw PdfExtractionException::fileNotFound($filePath);
        }

        try {
            $pdf = $this->parser->parseFile($realPath);
            $text = $pdf->getText();
        } catch (Throwable $e) {
            throw PdfExtractionException::corrupted($filePath, $e->getMessage());
        }

        $cleanText = trim((string) $text);

        if ($cleanText === '') {
            throw PdfExtractionException::emptyText($filePath);
        }

        return $cleanText;
    }

    /**
     * Resolve the absolute path from a storage path or local path.
     */
    protected function resolvePath(string $filePath, ?string $disk = null): string
    {
        $storage = Storage::disk($disk);

        if ($storage->exists($filePath)) {
            return $storage->path($filePath);
        }

        if (file_exists($filePath)) {
            return $filePath;
        }

        return $filePath;
    }
}
