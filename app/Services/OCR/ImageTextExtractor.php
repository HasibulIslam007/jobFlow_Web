<?php

namespace App\Services\OCR;

use App\Services\OCR\Providers\FakeOCRProvider;
use App\Services\OCR\Providers\GeminiVisionOCRProvider;
use App\Services\OCR\Providers\OCRProviderInterface;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImageTextExtractor
{
    public function __construct(
        protected ?OCRProviderInterface $provider = null
    ) {
        $this->provider = $provider ?? $this->resolveDefaultProvider();
    }

    /**
     * Build the configured OCR provider.
     *
     * Previously this returned `new FakeOCRProvider` unconditionally, so every
     * real image capture sent the literal string
     * "Default simulated OCR text extracted from image." to the extraction
     * prompt. Resolution now goes through config (`ai.ocr.provider`), and
     * `OCR_PROVIDER=fake` is set in phpunit.xml so the suite never reaches the
     * network.
     */
    protected function resolveDefaultProvider(): OCRProviderInterface
    {
        $config = config('ai.ocr', []);

        return match ($config['provider'] ?? 'fake') {
            'gemini' => new GeminiVisionOCRProvider($config),
            default => new FakeOCRProvider,
        };
    }

    /**
     * Get the active OCR provider.
     */
    public function getProvider(): OCRProviderInterface
    {
        return $this->provider;
    }

    /**
     * Set the OCR provider.
     */
    public function setProvider(OCRProviderInterface $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * Extract plain text from a stored image file.
     *
     * @param  string  $filePath  Storage relative path or absolute file path
     * @param  string|null  $disk  Filesystem disk (null defaults to configured default disk)
     *
     * @throws OCRException
     */
    public function extract(string $filePath, ?string $disk = null): string
    {
        $realPath = $this->resolvePath($filePath, $disk);

        if (! file_exists($realPath) || ! is_readable($realPath)) {
            throw OCRException::fileNotFound($filePath);
        }

        try {
            $text = $this->provider->extractText($realPath);
        } catch (OCRException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw OCRException::corrupted($filePath, $e->getMessage());
        }

        $cleanText = trim((string) $text);

        if ($cleanText === '') {
            throw OCRException::emptyText($filePath);
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
