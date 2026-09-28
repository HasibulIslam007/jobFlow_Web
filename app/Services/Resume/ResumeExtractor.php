<?php

namespace App\Services\Resume;

use App\Models\Resume;
use App\Services\PDF\PdfTextExtractor;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Extracts plain text from a stored resume file and normalizes it
 * for AI analysis.
 *
 * Dispatch is keyed on the resume's file type so new formats can be
 * added without touching callers:
 * - pdf  → PdfTextExtractor (Smalot parser)
 * - txt  → direct read (future)
 * - docx → reserved (future phase)
 */
class ResumeExtractor
{
    /**
     * Supported file types for extraction.
     *
     * @var array<int, string>
     */
    public const SUPPORTED = ['pdf'];

    public function __construct(
        protected PdfTextExtractor $pdfExtractor
    ) {}

    /**
     * Extract and normalize plain text for the given resume.
     *
     * @throws ResumeExtractionException
     */
    public function extract(Resume $resume, ?string $disk = null): string
    {
        $rawText = match ($resume->file_type) {
            'pdf' => $this->extractPdf($resume, $disk),
            default => throw ResumeExtractionException::unsupported($resume->file_type),
        };

        return $this->normalize($rawText);
    }

    /**
     * Normalize extracted text: collapse whitespace, trim edges.
     */
    public function normalize(string $text): string
    {
        // Normalize line endings, then collapse runs of blank lines
        // while preserving single newlines (structure matters to the AI).
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+/", ' ', (string) $text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);

        return trim((string) $text);
    }

    /**
     * @throws ResumeExtractionException
     */
    protected function extractPdf(Resume $resume, ?string $disk = null): string
    {
        if (! $this->fileExists($resume->file_path, $disk)) {
            throw ResumeExtractionException::fileNotFound($resume->file_path);
        }

        try {
            return $this->pdfExtractor->extract($resume->file_path, $disk);
        } catch (Throwable $e) {
            throw ResumeExtractionException::failed($e->getMessage(), $e);
        }
    }

    protected function fileExists(string $filePath, ?string $disk = null): bool
    {
        $storage = Storage::disk($disk);

        if ($storage->exists($filePath)) {
            return true;
        }

        return file_exists($filePath);
    }
}
