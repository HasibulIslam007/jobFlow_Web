<?php

namespace App\Services\AI\Exceptions;

use Exception;
use Throwable;

class AIException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        protected array $context = []
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    public static function providerUnavailable(string $provider, string $details = ''): self
    {
        return new self(
            "AI provider [{$provider}] is unavailable: {$details}",
            503,
            context: ['provider' => $provider, 'details' => $details]
        );
    }

    public static function invalidResponse(string $provider, string $rawResponse, string $reason): self
    {
        return new self(
            "Invalid response from AI provider [{$provider}]: {$reason}",
            422,
            context: ['provider' => $provider, 'raw' => $rawResponse, 'reason' => $reason]
        );
    }

    public static function requestFailed(string $provider, string $error, int $statusCode = 500, ?Throwable $previous = null): self
    {
        return new self(
            "AI request failed for [{$provider}]: {$error}",
            $statusCode,
            $previous,
            ['provider' => $provider, 'error' => $error]
        );
    }
}
