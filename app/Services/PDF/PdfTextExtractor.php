<?php

namespace App\Services\PDF;

use App\Services\OCR\Providers\GeminiVisionOCRProvider;
use App\Services\OCR\Providers\OCRProviderInterface;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Extracts text from a stored PDF.
 *
 * Depends on `OCRProviderInterface` rather than declaring a parallel
 * "vision reader" contract: that interface already means exactly this —
 * "turn a non-text file at this path into plain text" — and the Gemini
 * implementation is shared with the image capture path. One contract, one HTTP
 * call, one prompt.
 */
class PdfTextExtractor
{
    public function __construct(
        protected ?Parser $parser = null,
        protected ?OCRProviderInterface $visionFallback = null
    ) {
        $this->parser = $parser ?? new Parser;
        $this->visionFallback = $visionFallback ?? $this->resolveVisionFallback();
    }

    /**
     * Build the vision fallback, but ONLY when a real reader is configured.
     *
     * Returning null for the fake provider is deliberate and load-bearing. If
     * the fallback resolved to `FakeOCRProvider` it would hand the extraction
     * prompt the literal string "Default simulated OCR text extracted from
     * image." — silently creating a job from a placeholder, which is precisely
     * the bug this fallback exists to fix. With no real reader configured, the
     * accurate "no readable text" error is far better than a fabricated one.
     */
    protected function resolveVisionFallback(): ?OCRProviderInterface
    {
        $config = config('ai.ocr', []);

        if (($config['provider'] ?? 'fake') !== 'gemini') {
            return null;
        }

        return new GeminiVisionOCRProvider($config);
    }

    /**
     * Extract plain text from a stored PDF file.
     *
     * Two strategies, in order:
     *   1. text layer (fast, exact, free)
     *   2. vision read (a screenshot-as-PDF or a scan has no text layer)
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

        $text = $this->extractTextLayer($realPath, $filePath);

        if (trim((string) $text) !== '') {
            return trim((string) $text);
        }

        // No text layer. Before giving up, ask the vision reader: a PDF that
        // is really a screenshot parses to "" but is perfectly readable.
        if ($this->visionFallback !== null) {
            try {
                $visionText = trim($this->visionFallback->extractText($realPath));

                if ($visionText !== '') {
                    return $visionText;
                }
            } catch (Throwable $e) {
                // Report the *PDF's* real problem, not the fallback's: "the
                // scanner is down" is less useful to a user than "this file
                // has no readable text".
                throw PdfExtractionException::emptyText($filePath);
            }
        }

        throw PdfExtractionException::emptyText($filePath);
    }

    /**
     * Read the embedded text layer. A genuinely corrupt file is reported as
     * corrupt; a readable file with no text returns '' so the caller can fall
     * back to vision.
     */
    protected function extractTextLayer(string $realPath, string $filePath): string
    {
        try {
            return (string) $this->parser->parseFile($realPath)->getText();
        } catch (Throwable $e) {
            throw PdfExtractionException::corrupted($filePath, $e->getMessage());
        }
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
