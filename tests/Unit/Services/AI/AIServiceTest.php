<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\AIService;
use App\Services\AI\DTOs\AIResponseDTO;
use App\Services\AI\Exceptions\AIException;
use App\Services\AI\Providers\FakeAIProvider;
use Tests\TestCase;

class AIServiceTest extends TestCase
{
    public function test_ai_service_can_call_provider(): void
    {
        $fakeProvider = new FakeAIProvider;
        $fakeProvider->setResponse([
            'title' => 'Software Engineer',
            'company' => 'Acme Corp',
        ]);

        $aiService = new AIService([
            'provider' => 'fake',
            'providers' => ['fake' => []],
        ]);
        $aiService->extend('fake', $fakeProvider);

        $response = $aiService->generate('Test prompt');

        $this->assertInstanceOf(AIResponseDTO::class, $response);
        $this->assertTrue($response->isValid());
        $this->assertSame('Software Engineer', $response->data()['title']);
        $this->assertSame('Acme Corp', $response->data()['company']);
        $this->assertSame(1, $fakeProvider->getCallCount());
        $this->assertSame('Test prompt', $fakeProvider->getRecordedCalls()[0]['prompt']);
    }

    public function test_invalid_ai_response_is_handled(): void
    {
        $fakeProvider = new FakeAIProvider;
        $fakeProvider->setResponse('Not a valid JSON string');

        $aiService = new AIService([
            'provider' => 'fake',
            'providers' => ['fake' => []],
        ]);
        $aiService->extend('fake', $fakeProvider);

        $response = $aiService->generate('Test prompt');

        $this->assertInstanceOf(AIResponseDTO::class, $response);
        $this->assertFalse($response->isValid());
        $this->assertArrayHasKey('json', $response->validationErrors);
        $this->assertSame('Not a valid JSON string', $response->rawResponse);
        $this->assertEmpty($response->data());
    }

    public function test_ai_response_with_markdown_fences_is_parsed(): void
    {
        $fakeProvider = new FakeAIProvider;
        $fakeProvider->setResponse("```json\n{\"skills\": [\"PHP\", \"Laravel\"]}\n```");

        $aiService = new AIService([
            'provider' => 'fake',
            'providers' => ['fake' => []],
        ]);
        $aiService->extend('fake', $fakeProvider);

        $response = $aiService->generate('Extract skills');

        $this->assertTrue($response->isValid());
        $this->assertSame(['PHP', 'Laravel'], $response->data()['skills']);
    }

    public function test_provider_switching_works(): void
    {
        $fake1 = new FakeAIProvider;
        $fake1->setResponse(['source' => 'provider1']);

        $fake2 = new FakeAIProvider;
        $fake2->setResponse(['source' => 'provider2']);

        $aiService = new AIService([
            'provider' => 'fake1',
            'providers' => [
                'fake1' => [],
                'fake2' => [],
            ],
        ]);
        $aiService->extend('fake1', $fake1);
        $aiService->extend('fake2', $fake2);

        // Default calls fake1
        $response1 = $aiService->generate('Prompt 1');
        $this->assertSame('provider1', $response1->data()['source']);

        // Explicitly switch using `using('fake2')`
        $response2 = $aiService->using('fake2')->generate('Prompt 2');
        $this->assertSame('provider2', $response2->data()['source']);

        $this->assertSame(1, $fake1->getCallCount());
        $this->assertSame(1, $fake2->getCallCount());
    }

    public function test_provider_failure_throws_ai_exception(): void
    {
        $fakeProvider = new FakeAIProvider;
        $fakeProvider->setFailure(true, 'Rate limit exceeded', 429);

        $aiService = new AIService([
            'provider' => 'fake',
            'providers' => ['fake' => []],
        ]);
        $aiService->extend('fake', $fakeProvider);

        $this->expectException(AIException::class);
        $this->expectExceptionCode(429);
        $this->expectExceptionMessage('AI request failed for [fake]: Rate limit exceeded');

        $aiService->generate('Test prompt');
    }
}
