<?php

namespace App\Services\OCR\Providers;

use App\Services\OCR\OCRException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Real document reading via Gemini's vision model.
 *
 * WHY THIS EXISTS
 * ---------------
 * Two capture paths fed the model placeholder text instead of a real
 * transcription, and both surfaced as an AI error that looked like a flaky
 * provider:
 *
 *   1. `ImageTextExtractor` defaulted to `FakeOCRProvider`, which returns the
 *      literal string "Default simulated OCR text extracted from image."
 *   2. `PdfTextExtractor` uses a text-layer parser only. A PDF produced by
 *      screenshotting a job ad (or by a scanner) has an image and *no font*,
 *      so the parser correctly returns "" and the capture failed with
 *      "No readable text found in PDF … It may be scanned or empty."
 *
 * Both are the same problem: a document whose content is pixels. Reusing the
 * Gemini key the project is already configured with fixes it without adding
 * Tesseract or a second cloud vendor, and vision beats a classic OCR engine on
 * a job screenshot because it reads the column layout instead of flattening it.
 *
 * Handles images AND PDFs (`application/pdf`), so the two capture paths share
 * one HTTP call and one prompt rather than duplicating both.
 *
 * Asks for a *verbatim transcription*, not JSON — structuring is the next
 * step's job (ExtractJobAction), and keeping reading dumb keeps the two
 * concerns from collapsing into each other.
 */
class GeminiVisionOCRProvider implements OCRProviderInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config = []
    ) {}

    /**
     * Transcribe an image to plain text.
     *
     * @throws OCRException
     */
    public function extractText(string $absolutePath): string
    {
        $apiKey = (string) ($this->config['api_key'] ?? '');
        $model = (string) ($this->config['model'] ?? 'gemini-flash-lite-latest');
        $timeout = (int) ($this->config['timeout'] ?? 60);
        $baseUrl = (string) ($this->config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta');

        if ($apiKey === '') {
            throw OCRException::failed(
                'No OCR provider is configured: GEMINI_API_KEY is not set.'
            );
        }

        $bytes = @file_get_contents($absolutePath);

        if ($bytes === false || $bytes === '') {
            throw OCRException::fileNotFound($absolutePath);
        }

        $mime = $this->detectMimeType($absolutePath, $bytes);

        $url = "{$baseUrl}/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->timeout($timeout)
                ->post($url, [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => $this->transcriptionPrompt()],
                                [
                                    'inline_data' => [
                                        'mime_type' => $mime,
                                        'data' => base64_encode($bytes),
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0,
                    ],
                ]);
        } catch (Throwable $e) {
            // A transport failure here is a network problem, not a blank image,
            // so it must not be reported as unreadable text.
            throw OCRException::failed('Could not reach the OCR provider: '.$e->getMessage());
        }

        if ($response->failed()) {
            throw OCRException::failed(sprintf(
                'OCR provider returned HTTP %d: %s',
                $response->status(),
                $this->summarise($response->body())
            ));
        }

        $text = (string) ($response->json('candidates.0.content.parts.0.text') ?? '');

        if (trim($text) === '') {
            throw OCRException::emptyText($absolutePath);
        }

        return $text;
    }

    /**
     * Prompt tuned for transcription, not summarisation.
     */
    protected function transcriptionPrompt(): string
    {
        return implode(' ', [
            'Transcribe every piece of readable text in this document.',
            'Reproduce it verbatim, preserving reading order and line breaks.',
            'Include the job title, company name, location, salary, requirements,',
            'responsibilities and any apply link or deadline.',
            'This document may be a scanned page with no text layer, or a multi-page',
            'file — read whatever text is visible in the image content.',
            'Do not summarise, do not interpret and do not add commentary.',
            'If the document contains no readable text, reply with exactly: NO_TEXT_FOUND',
        ]);
    }

    /**
     * Resolve the MIME type, preferring the file itself over the extension so
     * a mislabelled upload still gets sent correctly.
     *
     * PDFs are supported alongside images: Gemini reads a PDF's text layer when
     * it has one and falls back to the rendered pages when it does not, which
     * is exactly the behaviour needed for a screenshot-as-PDF.
     */
    protected function detectMimeType(string $path, string $bytes): string
    {
        $guessed = @mime_content_type($path);

        if (is_string($guessed) && (str_starts_with($guessed, 'image/') || $guessed === 'application/pdf')) {
            return $guessed;
        }

        if (str_starts_with($bytes, '%PDF')) {
            return 'application/pdf';
        }

        if (str_starts_with($bytes, "\x89PNG")) {
            return 'image/png';
        }

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        return 'image/png';
    }

    /**
     * Keep provider error bodies short enough to log or display.
     */
    protected function summarise(string $body): string
    {
        $body = trim($body);

        return mb_strlen($body) > 300 ? mb_substr($body, 0, 300).'…' : $body;
    }
}
