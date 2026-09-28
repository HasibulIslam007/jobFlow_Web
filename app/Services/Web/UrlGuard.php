<?php

namespace App\Services\Web;

use App\Services\Web\Providers\HttpWebExtractor;

/**
 * Shared URL safety guards against SSRF.
 *
 * Offline checks (scheme, credentials, localhost, intranet names,
 * literal private IPs) run inside UrlContentExtractor on every path.
 * DNS-based checks additionally run inside HttpWebExtractor before
 * any real outbound request. Extracted so the logic is testable
 * without performing network I/O.
 *
 * @see HttpWebExtractor
 */
final class UrlGuard
{
    /**
     * @var array<int, string>
     */
    private const BLOCKED_NAMES = ['localhost'];

    /**
     * @var array<int, string>
     */
    private const BLOCKED_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home', '.corp'];

    /**
     * Parse a URL or throw for anything that is not an absolute http(s) URL.
     *
     * @return array{host: string}
     *
     * @throws WebExtractionException
     */
    public static function parseHttpUrl(string $url): array
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw WebExtractionException::invalidUrl($url);
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw WebExtractionException::invalidUrl($url);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw WebExtractionException::blockedUrl($url, 'URLs containing credentials are not allowed.');
        }

        $host = strtolower(trim($parts['host'], '.'));

        if ($host === '') {
            throw WebExtractionException::invalidUrl($url);
        }

        return ['host' => $host];
    }

    /**
     * Offline-safe checks: no DNS lookups performed.
     *
     * @throws WebExtractionException
     */
    public static function assertOfflineSafe(string $url): string
    {
        $host = self::parseHttpUrl($url)['host'];

        if (in_array($host, self::BLOCKED_NAMES, true)) {
            throw WebExtractionException::blockedUrl($url, 'Localhost URLs are not allowed.');
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw WebExtractionException::blockedUrl($url, 'Intranet/local network hostnames are not allowed.');
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

            if ($public === false) {
                throw WebExtractionException::blockedUrl($url, 'Private or reserved IP addresses are not allowed.');
            }
        }

        return $host;
    }
}
