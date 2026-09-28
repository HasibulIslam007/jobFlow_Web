<?php

namespace App\Services\Web\Providers;

use App\Services\Web\WebExtractionException;

interface WebExtractorInterface
{
    /**
     * Fetch a URL and return readable plain text.
     *
     * @throws WebExtractionException
     */
    public function extract(string $url): string;
}
