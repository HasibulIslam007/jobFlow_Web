<?php

namespace App\Services\OCR\Providers;

use App\Services\OCR\OCRException;
use Throwable;

class FakeOCRProvider implements OCRProviderInterface
{
    /**
     * @var string|callable|null
     */
    protected $textResponse = null;

    protected ?Throwable $exceptionToThrow = null;

    /**
     * Set the mocked text response or generator callback.
     */
    public function setTextResponse(string|callable $response): self
    {
        $this->textResponse = $response;

        return $this;
    }

    /**
     * Set an exception to be thrown upon extraction.
     */
    public function throwException(Throwable $exception): self
    {
        $this->exceptionToThrow = $exception;

        return $this;
    }

    /**
     * Extract plain text from the given path.
     *
     * @throws OCRException|Throwable
     */
    public function extractText(string $absolutePath): string
    {
        if ($this->exceptionToThrow !== null) {
            throw $this->exceptionToThrow;
        }

        if (is_callable($this->textResponse)) {
            return ($this->textResponse)($absolutePath);
        }

        if (is_string($this->textResponse)) {
            return $this->textResponse;
        }

        return 'Default simulated OCR text extracted from image.';
    }
}
