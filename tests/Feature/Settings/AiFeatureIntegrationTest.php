<?php

namespace Tests\Feature\Settings;

use App\Enums\AiMode;
use App\Models\Job;
use App\Models\User;
use App\Services\AI\AiCredentialResolver;
use App\Services\AI\AIService;
use App\Services\AI\DTOs\AIResponseDTO;
use App\Services\AI\Exceptions\AIException;
use App\Services\AI\Providers\AIProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BYOK applied to a REAL AI feature.
 *
 * The fallback unit tests drive AIService directly. This one proves the
 * integration claim: a job capture — which knows nothing about BYOK — routes
 * through the fallback because AIService is the chokepoint it already used
 * before this feature existed.
 */
class AiFeatureIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PLATFORM_KEY = 'platform-key-should-be-used-first';

    private const PERSONAL_KEY = 'AIzaSyPERSONALkey9999CCCC';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.provider' => 'fake',
            'ai.providers.gemini.api_key' => self::PLATFORM_KEY,
        ]);
    }

    /**
     * A provider that fails on the platform key and succeeds on the personal
     * one, recording the order it was called in.
     *
     * @param  array<int, string|null>  $calls
     */
    private function quotaThenSuccess(array &$calls): AIProviderInterface
    {
        // The exhausted key is passed in rather than read off the test class:
        // an anonymous class cannot reach a private constant on its parent,
        // and the resulting "Cannot access private constant" 500 would look
        // like a fault in the product rather than in this test.
        return new class($calls, self::PLATFORM_KEY) implements AIProviderInterface
        {
            /** @param array<int, string|null> $calls */
            public function __construct(
                private array &$calls,
                private string $exhaustedKey,
            ) {}

            public function getName(): string
            {
                return 'gemini';
            }

            public function generate(string $prompt, array $options = []): AIResponseDTO
            {
                $key = $options['api_key'] ?? null;
                $this->calls[] = $key;

                if ($key === $this->exhaustedKey) {
                    throw AIException::requestFailed('gemini', 'RESOURCE_EXHAUSTED', 429);
                }

                return AIResponseDTO::fromRaw(json_encode([
                    'title' => 'Platform Engineer',
                    'company' => 'Nimbus',
                    'location' => 'Remote',
                    'salary' => '$180k',
                    'deadline' => null,
                    'job_type' => 'full-time',
                    'experience' => '5+ years',
                    'education' => '',
                    'skills' => ['Go', 'Kubernetes'],
                    'responsibilities' => ['Build platforms'],
                    'benefits' => [],
                    'application_link' => '',
                ]), 'gemini', 'test');
            }
        };
    }

    private function bindService(AIProviderInterface $provider): void
    {
        $service = new AIService(['provider' => 'gemini', 'providers' => ['gemini' => []]]);
        $service->extend('gemini', $provider);
        $service = $service->withCredentials(app(AiCredentialResolver::class));

        $this->app->instance(AIService::class, $service);
    }

    public function test_a_text_capture_survives_platform_quota_via_the_personal_key(): void
    {
        $user = User::factory()->create(['ai_mode' => AiMode::Automatic->value]);
        app(AiCredentialResolver::class)->store($user, 'gemini', self::PERSONAL_KEY);

        $calls = [];
        $this->bindService($this->quotaThenSuccess($calls));

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/job-captures', [
                'type' => 'text',
                'content' => 'Platform Engineer at Nimbus. Remote. Go, Kubernetes. $180k.',
            ]);

        $response->assertCreated();

        // The capture is transparently retried on the user's own quota.
        $this->assertSame(
            [self::PLATFORM_KEY, self::PERSONAL_KEY],
            $calls,
            'The capture must fall back without any BYOK code in the feature.'
        );

        $this->assertDatabaseHas('user_jobs', [
            'user_id' => $user->id,
            'title' => 'Platform Engineer',
            'company' => 'Nimbus',
        ]);

        $this->assertCount(2, Job::where('user_id', $user->id)->firstOrFail()->skills);
    }

    public function test_a_capture_fails_with_guidance_when_no_personal_key_exists(): void
    {
        $user = User::factory()->create(['ai_mode' => AiMode::Automatic->value]);

        $calls = [];
        $this->bindService($this->quotaThenSuccess($calls));

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/job-captures', [
                'type' => 'text',
                'content' => 'Platform Engineer at Nimbus.',
            ]);

        // Job captures answer 201 and carry the outcome in the body; a failed
        // extraction is data.status = "failed", not an HTTP error status.
        $response->assertCreated()
            ->assertJsonPath('data.status', 'failed');

        $this->assertStringContainsString('AI Settings', $response->json('data.error_message'));
        $this->assertDatabaseCount('user_jobs', 0);
        $this->assertCount(1, $calls, 'No key to retry with, so exactly one call.');
    }

    public function test_a_capture_in_personal_mode_never_consults_the_platform_key(): void
    {
        $user = User::factory()->create(['ai_mode' => AiMode::Personal->value]);
        app(AiCredentialResolver::class)->store($user, 'gemini', self::PERSONAL_KEY);

        $calls = [];
        // Fails on the PLATFORM key only, so a personal-mode run must succeed
        // first time — proving the platform key was never consulted.
        $this->bindService($this->quotaThenSuccess($calls));

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/job-captures', [
                'type' => 'text',
                'content' => 'Platform Engineer at Nimbus. Remote. Go, Kubernetes.',
            ]);

        $response->assertCreated();

        $this->assertSame(
            [self::PERSONAL_KEY],
            $calls,
            'personal mode must never touch the platform key.'
        );
        $this->assertNotContains(self::PLATFORM_KEY, $calls);
    }
}
