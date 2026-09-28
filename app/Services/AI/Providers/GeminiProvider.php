<?php

namespace App\Services\AI\Providers;

use App\Services\AI\DTOs\AIResponseDTO;
use App\Services\AI\Exceptions\AIException;
use Illuminate\Support\Facades\Http;
use Throwable;

class GeminiProvider implements AIProviderInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config = []
    ) {}

    public function getName(): string
    {
        return 'gemini';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function generate(string $prompt, array $options = []): AIResponseDTO
    {
        $apiKey = $options['api_key'] ?? $this->config['api_key'] ?? '';
        $model = $options['model'] ?? $this->config['model'] ?? 'gemini-1.5-flash';
        $timeout = $options['timeout'] ?? $this->config['timeout'] ?? 30;
        $baseUrl = $this->config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta';

        if (empty($apiKey)) {
            throw AIException::providerUnavailable($this->getName(), 'Missing Gemini API key');
        }

        $systemPrompt = $options['system_prompt'] ?? 'You are an intelligent assistant that strictly responds in valid JSON format.';
        $url = "{$baseUrl}/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])
                ->timeout($timeout)
                ->post($url, [
                    'systemInstruction' => [
                        'parts' => [
                            ['text' => $systemPrompt],
                        ],
                    ],
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'temperature' => $options['temperature'] ?? 0.1,
                    ],
                ]);

            if ($response->failed()) {
                throw AIException::requestFailed(
                    $this->getName(),
                    $response->body(),
                    $response->status()
                );
            }

            $json = $response->json();
            $rawContent = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $meta = [
                'usageMetadata' => $json['usageMetadata'] ?? [],
                'finishReason' => $json['candidates'][0]['finishReason'] ?? null,
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
