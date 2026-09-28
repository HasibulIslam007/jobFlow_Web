<?php

namespace App\Services\AI\Providers;

use App\Services\AI\DTOs\AIResponseDTO;
use App\Services\AI\Exceptions\AIException;

class FakeAIProvider implements AIProviderInterface
{
    /**
     * @var array<string, mixed>|string
     */
    protected array|string $responsePayload = '{"status":"ok"}';

    protected bool $shouldFail = false;

    protected string $failureMessage = 'Fake provider failed.';

    protected int $failureCode = 500;

    /**
     * @var array<int, array{prompt: string, options: array<string, mixed>}>
     */
    protected array $recordedCalls = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config = []
    ) {}

    public function getName(): string
    {
        return 'fake';
    }

    /**
     * @param  array<string, mixed>|string  $payload
     */
    public function setResponse(array|string $payload): self
    {
        $this->responsePayload = $payload;

        return $this;
    }

    public function setFailure(bool $fail = true, string $message = 'Fake provider failed.', int $code = 500): self
    {
        $this->shouldFail = $fail;
        $this->failureMessage = $message;
        $this->failureCode = $code;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function generate(string $prompt, array $options = []): AIResponseDTO
    {
        $this->recordedCalls[] = [
            'prompt' => $prompt,
            'options' => $options,
        ];

        if ($this->shouldFail) {
            throw AIException::requestFailed($this->getName(), $this->failureMessage, $this->failureCode);
        }

        $raw = is_array($this->responsePayload)
            ? json_encode($this->responsePayload, JSON_THROW_ON_ERROR)
            : $this->responsePayload;

        $model = $options['model'] ?? $this->config['model'] ?? 'fake-model';

        return AIResponseDTO::fromRaw($raw, $this->getName(), $model, ['fake' => true]);
    }

    /**
     * @return array<int, array{prompt: string, options: array<string, mixed>}>
     */
    public function getRecordedCalls(): array
    {
        return $this->recordedCalls;
    }

    public function getCallCount(): int
    {
        return count($this->recordedCalls);
    }
}
