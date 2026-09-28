<?php

namespace App\Services\Web\Providers;

use App\Services\Web\UrlGuard;
use App\Services\Web\WebExtractionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches a public job posting page over plain HTTP and returns
 * readable plain text. No browser automation, no premium scraping APIs.
 */
class HttpWebExtractor implements WebExtractorInterface
{
    public function __construct(
        protected int $timeoutSeconds = 10,
        protected int $maxBytes = 2000000
    ) {}

    /**
     * @throws WebExtractionException
     */
    public function extract(string $url): string
    {
        $this->assertSafeUrl($url);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->connectTimeout(min(5, $this->timeoutSeconds))
                ->withHeaders([
                    'User-Agent' => 'JobFlowBot/1.0 (+job-capture; contact: support@example.com)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($url);
        } catch (ConnectionException $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
                throw WebExtractionException::timeout($url);
            }

            throw WebExtractionException::requestFailed($url, $e->getMessage());
        } catch (Throwable $e) {
            throw WebExtractionException::requestFailed($url, $e->getMessage());
        }

        if (! $response->successful()) {
            throw WebExtractionException::requestFailed($url, "Remote server responded with HTTP {$response->status()}.");
        }

        $html = (string) $response->body();

        if (strlen($html) > $this->maxBytes) {
            throw WebExtractionException::failed("Page at [{$url}] exceeds the fetch size limit.");
        }

        $text = HtmlCleaner::clean($html);

        if (trim($text) === '') {
            throw WebExtractionException::emptyContent($url);
        }

        return $text;
    }

    /**
     * Guard against SSRF: only public http/https URLs, no credentials,
     * no localhost / private / reserved targets. Runs DNS checks so it
     * must only execute on the real HTTP path (never on fakes in tests).
     *
     * @throws WebExtractionException
     */
    public function assertSafeUrl(string $url): void
    {
        $host = UrlGuard::assertOfflineSafe($url);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // Literal public IP already validated offline above.
            return;
        }

        $ips = $this->resolveHostIps($host);

        if ($ips === []) {
            throw WebExtractionException::requestFailed($url, 'Could not resolve host.');
        }

        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) {
                throw WebExtractionException::blockedUrl($url, 'Host resolves to a private or reserved IP address.');
            }
        }
    }

    /**
     * @return array<int, string>
     */
    protected function resolveHostIps(string $host): array
    {
        $ips = [];

        try {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);

            if (is_array($records)) {
                foreach ($records as $record) {
                    if (! empty($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                    if (! empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        } catch (Throwable) {
            // Fall through to gethostbynamel below.
        }

        if ($ips === []) {
            try {
                $v4 = gethostbynamel($host);

                if (is_array($v4)) {
                    $ips = array_merge($ips, $v4);
                }
            } catch (Throwable) {
                // DNS failure surfaces as an empty result.
            }
        }

        return array_values(array_unique($ips));
    }
}
