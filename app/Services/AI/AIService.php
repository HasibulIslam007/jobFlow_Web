<?php

namespace App\Services\AI;

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
        protected array $config = []
    ) {}

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
     * @param  array<string, mixed>  $options
     */
    public function generate(string $prompt, array $options = []): AIResponseDTO
    {
        $provider = $this->getProvider();

        try {
            return $provider->generate($prompt, $options);
        } catch (AIException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw AIException::requestFailed($provider->getName(), $e->getMessage(), 500, $e);
        }
    }
}
