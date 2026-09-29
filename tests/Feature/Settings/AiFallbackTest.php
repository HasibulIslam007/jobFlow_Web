<?php

namespace Tests\Feature\Settings;

use App\Enums\AiMode;
use App\Models\User;
use App\Services\AI\AiCredentialResolver;
use App\Services\AI\AiQuotaDetector;
use App\Services\AI\AIService;
use App\Services\AI\DTOs\AIResponseDTO;
use App\Services\AI\Exceptions\AIException;
use App\Services\AI\Providers\AIProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BYOK — mode selection and the quota fallback, exercised through the real
 * AIService with a scripted provider.
 *
 * These are the tests that matter most: they prove BYOK applies at the single
 * chokepoint every AI feature passes through, and that the fallback is narrow
 * enough not to fire on our own bugs.
 */
class AiFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const PLATFORM_KEY = 'platform-key-should-be-used-first';

    private const PERSONAL_KEY = 'AIzaSyPERSONALkey9999CCCC';

    /**
     * phpunit.xml ships an empty GEMINI_API_KEY, which would leave the resolver
     * with no platform key and make every "platform quota exhausted" scenario
     * unreachable. A stand-in platform key is set per test so the fallback path
     * is genuinely exercised.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.provider' => 'gemini',
            'ai.providers.gemini.api_key' => self::PLATFORM_KEY,
        ]);
    }

    /**
     * A provider that records which key it was handed and fails in a
     * configurable way. Stands in for Gemini without mocking the service
     * under test.
     *
     * @param  array<int, string|null>  $calls
     */
    private function scriptProvider(
        array &$calls,
        ?string $failWithKey = null,
        int $failStatus = 429,
        string $failBody = 'RESOURCE_EXHAUSTED: quota exceeded',
    ): AIProviderInterface {
        return new class($calls, $failWithKey, $failStatus, $failBody) implements AIProviderInterface
        {
            /** @param array<int, string|null> $calls */
            public function __construct(
                private array &$calls,
                private ?string $failWithKey,
                private int $failStatus,
                private string $failBody,
            ) {}

            public function getName(): string
            {
                return 'gemini';
            }

            public function generate(string $prompt, array $options = []): AIResponseDTO
            {
                $key = $options['api_key'] ?? null;
                $this->calls[] = $key;

                if ($this->failWithKey !== null && $key === $this->failWithKey) {
                    throw AIException::requestFailed('gemini', $this->failBody, $this->failStatus);
                }

                return AIResponseDTO::fromRaw('{"title":"Engineer","company":"Acme"}', 'gemini', 'test');
            }
        };
    }

    private function serviceFor(AIProviderInterface $provider, User $user): AIService
    {
        $service = new AIService(['provider' => 'gemini', 'providers' => ['gemini' => []]]);
        $service->extend('gemini', $provider);
        $this->actingAs($user, 'web');

        return $service->withCredentials(app(AiCredentialResolver::class));
    }

    private function userWithKey(AiMode $mode): User
    {
        $user = User::factory()->create(['ai_mode' => $mode->value]);
        app(AiCredentialResolver::class)->store($user, 'gemini', self::PERSONAL_KEY);

        return $user;
    }

    // ------------------------------------------------------------ mode rules

    public function test_automatic_mode_uses_the_platform_key_first(): void
    {
        $user = $this->userWithKey(AiMode::Automatic);

        $calls = [];
        $this->serviceFor($this->scriptProvider($calls), $user)->generate('prompt');

        $this->assertSame([self::PLATFORM_KEY], $calls, 'The platform key must be tried first.');
    }

    public function test_personal_mode_uses_the_users_key_and_never_the_platform_one(): void
    {
        $user = $this->userWithKey(AiMode::Personal);

        $calls = [];
        $this->serviceFor($this->scriptProvider($calls), $user)->generate('prompt');

        $this->assertSame([self::PERSONAL_KEY], $calls);
        $this->assertNotContains(self::PLATFORM_KEY, $calls);
    }

    public function test_jobflow_mode_never_uses_the_personal_key(): void
    {
        $user = $this->userWithKey(AiMode::JobFlow);

        $calls = [];
        // Even when the platform key is exhausted, `jobflow` must not fall back.
        $service = $this->serviceFor($this->scriptProvider($calls, self::PLATFORM_KEY), $user);

        try {
            $service->generate('prompt');
            $this->fail('Expected an AIException.');
        } catch (AIException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(
            [self::PLATFORM_KEY],
            $calls,
            'jobflow mode must make exactly one call, with the platform key.'
        );
    }

    public function test_personal_mode_never_falls_back_to_the_platform_key(): void
    {
        $user = $this->userWithKey(AiMode::Personal);

        $calls = [];
        $service = $this->serviceFor($this->scriptProvider($calls, self::PERSONAL_KEY), $user);

        try {
            $service->generate('prompt');
            $this->fail('Expected an AIException.');
        } catch (AIException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(
            [self::PERSONAL_KEY],
            $calls,
            'A failing personal key must not silently swap to the platform key.'
        );
    }

    // -------------------------------------------------------------- fallback

    public function test_automatic_mode_falls_back_to_the_personal_key_on_quota(): void
    {
        $user = $this->userWithKey(AiMode::Automatic);

        $calls = [];
        $service = $this->serviceFor(
            $this->scriptProvider($calls, self::PLATFORM_KEY, 429, 'RESOURCE_EXHAUSTED'),
            $user
        );

        $response = $service->generate('prompt');

        $this->assertSame(
            [self::PLATFORM_KEY, self::PERSONAL_KEY],
            $calls,
            'A quota error must retry once with the personal key.'
        );
        $this->assertTrue($response->isValid(), 'The user should simply get their result.');
    }

    public function test_quota_wording_variants_all_trigger_fallback(): void
    {
        $user = $this->userWithKey(AiMode::Automatic);

        foreach (['rate limit exceeded', 'Too Many Requests', 'RESOURCE_EXHAUSTED'] as $body) {
            $calls = [];
            $this->serviceFor($this->scriptProvider($calls, self::PLATFORM_KEY, 429, $body), $user)
                ->generate('prompt');

            $this->assertCount(2, $calls, "'{$body}' should trigger a fallback.");
        }
    }

    public function test_http_503_also_triggers_fallback(): void
    {
        $user = $this->userWithKey(AiMode::Automatic);

        $calls = [];
        $this->serviceFor(
            $this->scriptProvider($calls, self::PLATFORM_KEY, 503, 'upstream unavailable'),
            $user
        )->generate('prompt');

        $this->assertCount(2, $calls);
    }

    /**
     * A 401 means the key we used is wrong. Swapping in another key would hide
     * a misconfiguration rather than fix it, and would spend the user's quota.
     */
    public function test_an_unauthorised_key_does_not_trigger_fallback(): void
    {
        $user = $this->userWithKey(AiMode::Automatic);

        $calls = [];
        $service = $this->serviceFor(
            $this->scriptProvider($calls, self::PLATFORM_KEY, 401, 'API key not valid'),
            $user
        );

        try {
            $service->generate('prompt');
            $this->fail('Expected an AIException.');
        } catch (AIException $e) {
            $this->assertSame(401, $e->getCode());
        }

        $this->assertSame([self::PLATFORM_KEY], $calls, 'No fallback on 401.');
    }

    /**
     * An unparseable model response is OUR bug. Re-running it under the user's
     * own paid quota would charge them for our mistake.
     */
    public function test_an_invalid_response_is_not_treated_as_a_quota_problem(): void
    {
        $this->assertFalse(
            AiQuotaDetector::isRetryable(
                AIException::invalidResponse('gemini', 'garbage', 'missing fields')
            ),
            'An invalid response is our bug, not their quota.'
        );
        $this->assertFalse(
            AiQuotaDetector::isRetryable(
                AIException::requestFailed('gemini', 'bad request', 400)
            ),
            'A malformed request must never be retried under another key.'
        );
    }

    public function test_quota_with_no_personal_key_returns_actionable_guidance(): void
    {
        $user = User::factory()->create(['ai_mode' => AiMode::Automatic->value]);

        $calls = [];
        $service = $this->serviceFor(
            $this->scriptProvider($calls, self::PLATFORM_KEY, 429, 'RESOURCE_EXHAUSTED'),
            $user
        );

        try {
            $service->generate('prompt');
            $this->fail('Expected an AIException.');
        } catch (AIException $e) {
            $this->assertSame(429, $e->getCode());
            $this->assertStringContainsString('AI Settings', $e->getMessage());
            $this->assertSame('add_personal_api_key', $e->getContext()['remedy'] ?? null);
        }

        $this->assertCount(1, $calls, 'With no key to retry, do not re-call.');
    }

    public function test_personal_mode_with_no_saved_key_does_not_borrow_the_platform_key(): void
    {
        $user = User::factory()->create(['ai_mode' => AiMode::Personal->value]);

        $this->assertNull(
            app(AiCredentialResolver::class)->primaryKey($user, 'gemini', self::PLATFORM_KEY),
            'personal mode with no stored key must not silently use the platform key.'
        );
    }

    public function test_when_both_keys_fail_the_error_carries_no_secret(): void
    {
        $user = $this->userWithKey(AiMode::Automatic);

        $calls = [];
        $provider = new class($calls) implements AIProviderInterface
        {
            /** @param array<int, string|null> $calls */
            public function __construct(private array &$calls) {}

            public function getName(): string
            {
                return 'gemini';
            }

            public function generate(string $prompt, array $options = []): AIResponseDTO
            {
                $this->calls[] = $options['api_key'] ?? null;

                throw AIException::requestFailed('gemini', 'RESOURCE_EXHAUSTED', 429);
            }
        };

        $service = $this->serviceFor($provider, $user);

        try {
            $service->generate('prompt');
            $this->fail('Expected an AIException.');
        } catch (AIException $e) {
            $this->assertStringNotContainsString(
                self::PERSONAL_KEY,
                $e->getMessage(),
                'A key leaked into the error message.'
            );
        }

        $this->assertCount(2, $calls, 'Exactly one retry — never an unbounded loop.');
    }
}
