<?php

namespace App\Services\Web\Providers;

use App\Services\Web\WebExtractionException;
use Throwable;

class FakeWebExtractor implements WebExtractorInterface
{
    /**
     * @var string|callable|null
     */
    protected $contentResponse = null;

    protected ?Throwable $exceptionToThrow = null;

    /**
     * Set the mocked content response or generator callback.
     *
     * @param  string|callable(string): string  $response
     */
    public function setContentResponse(string|callable $response): self
    {
        $this->contentResponse = $response;

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
     * @throws WebExtractionException|Throwable
     */
    public function extract(string $url): string
    {
        if ($this->exceptionToThrow !== null) {
            throw $this->exceptionToThrow;
        }

        if (is_callable($this->contentResponse)) {
            return ($this->contentResponse)($url);
        }

        if (is_string($this->contentResponse)) {
            return $this->contentResponse;
        }

        return 'Default simulated web content extracted from URL.';
    }
}
