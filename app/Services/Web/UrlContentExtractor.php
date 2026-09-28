<?php

namespace App\Services\Web;

use App\Services\Web\Providers\HttpWebExtractor;
use App\Services\Web\Providers\WebExtractorInterface;

class UrlContentExtractor
{
    public function __construct(
        protected ?WebExtractorInterface $provider = null
    ) {
        $this->provider = $provider ?? new HttpWebExtractor;
    }

    /**
     * Get the active web extractor provider.
     */
    public function getProvider(): WebExtractorInterface
    {
        return $this->provider;
    }

    /**
     * Set the web extractor provider.
     */
    public function setProvider(WebExtractorInterface $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * Fetch a job posting URL and return readable plain text.
     *
     * @throws WebExtractionException
     */
    public function extract(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw WebExtractionException::invalidUrl($url);
        }

        // Offline SSRF guards run on every path (including Fake providers in tests).
        UrlGuard::assertOfflineSafe($url);

        $text = trim((string) $this->provider->extract($url));

        if ($text === '') {
            throw WebExtractionException::emptyContent($url);
        }

        return $text;
    }
}
