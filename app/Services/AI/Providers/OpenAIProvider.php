<?php

namespace App\Services\AI\Providers;

use App\Services\AI\DTOs\AIResponseDTO;
use App\Services\AI\Exceptions\AIException;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenAIProvider implements AIProviderInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config = []
    ) {}

    public function getName(): string
    {
        return 'openai';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function generate(string $prompt, array $options = []): AIResponseDTO
    {
        $apiKey = $options['api_key'] ?? $this->config['api_key'] ?? '';
        $model = $options['model'] ?? $this->config['model'] ?? 'gpt-4o-mini';
        $timeout = $options['timeout'] ?? $this->config['timeout'] ?? 30;
        $baseUrl = $this->config['base_url'] ?? 'https://api.openai.com/v1';

        if (empty($apiKey)) {
            throw AIException::providerUnavailable($this->getName(), 'Missing OpenAI API key');
        }

        $systemPrompt = $options['system_prompt'] ?? 'You are an intelligent assistant that strictly responds in valid JSON format.';

        try {
            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->post("{$baseUrl}/chat/completions", [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => $options['temperature'] ?? 0.1,
                ]);

            if ($response->failed()) {
                throw AIException::requestFailed(
                    $this->getName(),
                    $response->body(),
                    $response->status()
                );
            }

            $json = $response->json();
            $rawContent = $json['choices'][0]['message']['content'] ?? '';
            $meta = [
                'usage' => $json['usage'] ?? [],
                'finish_reason' => $json['choices'][0]['finish_reason'] ?? null,
            ];

            return AIResponseDTO::fromRaw($rawContent, $this->getName(), $model, $meta);
        } catch (AIException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw AIException::requestFailed(
                $this->getName(),
                $e->getMessage(),
                500,
                $e
            );
        }
    }
}
