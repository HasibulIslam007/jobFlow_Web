<?php

namespace App\Services\AI;

use App\Models\User;
use App\Services\AI\DTOs\AIResponseDTO;
use App\Services\AI\Exceptions\AIException;
use App\Services\AI\Providers\AIProviderInterface;
use App\Services\AI\Providers\FakeAIProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\OpenAIProvider;
use InvalidArgumentException;

class AIService
{
    /**
     * @var array<string, AIProviderInterface>
     */
    protected array $resolvedProviders = [];

    protected ?string $customProvider = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config = [],
        protected ?AiCredentialResolver $credentials = null,
    ) {}

    /**
     * Swap the credential resolver (used by tests to inject a stub).
     */
    public function withCredentials(AiCredentialResolver $credentials): self
    {
        $clone = clone $this;
        $clone->credentials = $credentials;

        return $clone;
    }

    /**
     * Explicitly specify provider to use for the next call.
     */
    public function using(string $provider): self
    {
        $clone = clone $this;
        $clone->customProvider = $provider;

        return $clone;
    }

    /**
     * Register a custom or mock provider instance.
     */
    public function extend(string $name, AIProviderInterface $provider): self
    {
        $this->resolvedProviders[$name] = $provider;

        return $this;
    }

    /**
     * Get or resolve active AI provider.
     */
    public function getProvider(?string $name = null): AIProviderInterface
    {
        $providerName = $name ?? $this->customProvider ?? $this->config['provider'] ?? 'openai';

        if (isset($this->resolvedProviders[$providerName])) {
            return $this->resolvedProviders[$providerName];
        }

        $providerConfig = $this->config['providers'][$providerName] ?? [];

        $instance = match ($providerName) {
            'openai' => new OpenAIProvider($providerConfig),
            'gemini' => new GeminiProvider($providerConfig),
            'fake' => new FakeAIProvider($providerConfig),
            default => throw new InvalidArgumentException("Unsupported AI provider [{$providerName}]."),
        };

        return $this->resolvedProviders[$providerName] = $instance;
    }

    /**
     * Generate completion via configured provider.
     *
     * BYOK LIVES HERE, AND ONLY HERE
     * ------------------------------
     * Every AI feature in the product (job capture for text/PDF/image/URL,
     * job intelligence, resume analysis, resume/job matching) reaches the
     * provider through this one method. Resolving the credential and applying
     * the fallback here means all of them gain BYOK support at once, with no
     * change to any caller and no second AI system.
     *
     * Flow:
     *   1. resolve the key for the current user (see AiCredentialResolver)
     *   2. call the provider
     *   3. on a quota/rate-limit failure only, retry once with the user's own
     *      key if their mode permits it
     *   4. if there is no key to retry with, raise a quota error that says
     *      what to do about it
     *
     * @param  array<string, mixed>  $options
     *
     * @throws AIException
     */
    public function generate(string $prompt, array $options = []): AIResponseDTO
    {
        $provider = $this->getProvider();
        $user = $this->currentUser();

        // No resolver (older call sites, plain unit tests) or no authenticated
        // user (console, queue worker): behave exactly as before.
        if ($this->credentials === null || $user === null) {
            return $this->callProvider($provider, $prompt, $options, null);
        }

        $providerName = $provider->getName();
        $primaryKey = $this->credentials->primaryKey(
            $user,
            $providerName,
            $this->credentials->platformKey($providerName),
        );

        try {
            return $this->callProvider($provider, $prompt, $options, $primaryKey);
        } catch (AIException $e) {
            if (! AiQuotaDetector::isRetryable($e)) {
                throw $e;
            }

            $fallbackKey = $this->credentials->fallbackKey($user, $providerName, $primaryKey);

            if ($fallbackKey === null) {
                // Either the user has no key, or their mode forbids falling
                // back. In both cases the original quota error is accurate;
                // only the "no key at all" case gets extra guidance.
                if (! $this->credentials->hasPersonalKey($user, $providerName)) {
                    throw $this->quotaGuidance($e, $providerName);
                }

                throw $e;
            }

            $this->credentials->logFallback($providerName, $user, [
                'reason' => 'platform_quota_exhausted',
            ]);

            return $this->callProvider($provider, $prompt, $options, $fallbackKey);
        }
    }

    /**
     * Call the provider with a specific credential.
     *
     * A null key leaves `$options` untouched, so the provider falls back to
     * its own configured key exactly as it did before BYOK existed.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws AIException
     */
    private function callProvider(
        AIProviderInterface $provider,
        string $prompt,
        array $options,
        ?string $apiKey
    ): AIResponseDTO {
        if ($apiKey !== null) {
            $options['api_key'] = $apiKey;
        }

        try {
            return $provider->generate($prompt, $options);
        } catch (AIException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw AIException::requestFailed($provider->getName(), $e->getMessage(), 500, $e);
        }
    }

    /**
     * The authenticated user, or null outside an HTTP request.
     *
     * Resolved at call time rather than injected, so a long-lived singleton
     * never caches one request's user into the next.
     *
     * `request()->user()` is tried first: it reflects whatever guard the
     * route's `auth:sanctum` middleware actually resolved, which is not always
     * the application's default guard. `auth()->user()` is the fallback for
     * non-HTTP contexts.
     */
    private function currentUser(): ?User
    {
        $user = request()->user() ?? auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Turn a bare quota failure into something a user can act on.
     */
    private function quotaGuidance(AIException $e, string $providerName): AIException
    {
        return new AIException(
            "JobFlow AI has temporarily reached its {$providerName} usage limit. "
            .'Add your own '.$providerName.' API key in AI Settings to continue.',
            429,
            $e,
            array_merge($e->getContext(), [
                'provider' => $providerName,
                'reason' => 'platform_quota_exhausted',
                'remedy' => 'add_personal_api_key',
            ])
        );
    }
}
