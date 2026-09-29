<?php

namespace App\Services\AI;

use App\Services\AI\Exceptions\AIException;

/**
 * Decides whether an AI failure is worth retrying with a different key.
 *
 * This is deliberately narrow. Retrying on *any* error would be actively
 * harmful: a malformed prompt or an unparseable response is a bug in the
 * caller, and silently re-running it under a user's own paid quota would
 * charge them for our mistake.
 *
 * Only conditions that a DIFFERENT key can plausibly fix qualify:
 *   · HTTP 429              — quota / rate limit
 *   · HTTP 503              — provider temporarily unavailable
 *   · RESOURCE_EXHAUSTED    — Gemini's quota signal in the body
 *   · "quota" / "rate limit" in the provider's own wording
 *
 * Explicitly NOT retryable: 400 (bad request/prompt/model), 401/403 (the key
 * we used is the wrong one — swapping to another key would hide the problem
 * rather than solve it), and 422 invalidResponse (our bug, not theirs).
 */
final class AiQuotaDetector
{
    /**
     * Substrings providers use for quota/rate-limit conditions.
     *
     * @var array<int, string>
     */
    private const MARKERS = [
        'resource_exhausted',
        'quota exceeded',
        'quota_exceeded',
        'rate limit',
        'rate_limit',
        'ratelimit',
        'too many requests',
        'resource has been exhausted',
    ];

    public static function isRetryable(AIException $exception): bool
    {
        $status = $exception->getCode();

        if ($status === 429 || $status === 503) {
            return true;
        }

        return self::mentionsQuota($exception->getMessage())
            || self::mentionsQuota((string) ($exception->getContext()['error'] ?? ''));
    }

    private static function mentionsQuota(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        $haystack = mb_strtolower($text);

        foreach (self::MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }
}
