<?php

namespace Tests\Feature\AI;

use App\AI\Prompts\JobExtractionPrompt;
use App\Services\AI\AIService;
use App\Services\AI\DTOs\AIResponseDTO;
use App\Services\AI\Exceptions\AIException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AIIntegrationTest extends TestCase
{
    public function test_openai_provider_handles_http_success(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '{"title":"Backend Developer","company":"TechCo"}',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 15,
                    'completion_tokens' => 20,
                    'total_tokens' => 35,
                ],
            ], 200),
        ]);

        config([
            'ai.provider' => 'openai',
            'ai.providers.openai.api_key' => 'test-openai-key',
        ]);

        $service = app(AIService::class);
        $prompt = new JobExtractionPrompt('We are hiring a Backend Developer at TechCo');

        $response = $service->generate($prompt->render(), $prompt->options());

        $this->assertInstanceOf(AIResponseDTO::class, $response);
        $this->assertTrue($response->isValid());
        $this->assertSame('openai', $response->provider);
        $this->assertSame('Backend Developer', $response->data()['title']);
        $this->assertSame('TechCo', $response->data()['company']);
    }

    public function test_gemini_provider_handles_http_success(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"title":"Full Stack Engineer","company":"StartupInc"}'],
                            ],
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 12,
                    'candidatesTokenCount' => 18,
                ],
            ], 200),
        ]);

        config([
            'ai.provider' => 'gemini',
            'ai.providers.gemini.api_key' => 'test-gemini-key',
        ]);

        $service = app(AIService::class);
        $prompt = new JobExtractionPrompt('Looking for Full Stack Engineer at StartupInc');

        $response = $service->generate($prompt->render(), $prompt->options());

        $this->assertInstanceOf(AIResponseDTO::class, $response);
        $this->assertTrue($response->isValid());
        $this->assertSame('gemini', $response->provider);
        $this->assertSame('Full Stack Engineer', $response->data()['title']);
    }

    public function test_openai_provider_handles_http_error(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'Rate limit reached',
                ],
            ], 429),
        ]);

        config([
            'ai.provider' => 'openai',
            'ai.providers.openai.api_key' => 'test-key',
        ]);

        $service = app(AIService::class);

        $this->expectException(AIException::class);
        $this->expectExceptionCode(429);

        $service->generate('Test prompt');
    }

    public function test_missing_api_key_throws_provider_unavailable_exception(): void
    {
        config([
            'ai.provider' => 'openai',
            'ai.providers.openai.api_key' => '',
            'ai.api_key' => '',
        ]);

        $service = new AIService(config('ai'));

        $this->expectException(AIException::class);
        $this->expectExceptionCode(503);
        $this->expectExceptionMessage('AI provider [openai] is unavailable: Missing OpenAI API key');

        $service->generate('Test prompt');
    }
}
