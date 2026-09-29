<?php

namespace Tests\Feature\Settings;

use App\Enums\AiMode;
use App\Models\User;
use App\Models\UserAiCredential;
use App\Services\AI\AiCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BYOK — credentials, isolation, encryption and persistence.
 *
 * Security properties being defended:
 *   1. ISOLATION — a user can only address their own credential. The routes
 *      carry no id parameter, so that is asserted end-to-end.
 *   2. ENCRYPTION AT REST — the raw column must not contain the key.
 *   3. NO EGRESS — a usable key must never appear in a response.
 *   4. PERSISTENCE — the credential belongs to the account, not the session.
 */
class AiCredentialTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'AIzaSyTESTKEYdontcommit7F2A';

    private function resolver(): AiCredentialResolver
    {
        return app(AiCredentialResolver::class);
    }

    // ------------------------------------------------------------- isolation

    public function test_guest_cannot_reach_ai_settings(): void
    {
        $this->getJson('/api/v1/settings/ai')->assertStatus(401);
        $this->putJson('/api/v1/settings/ai', ['mode' => 'jobflow'])->assertStatus(401);
        $this->postJson('/api/v1/settings/ai/test', [])->assertStatus(401);
        $this->deleteJson('/api/v1/settings/ai/gemini')->assertStatus(401);
    }

    public function test_a_user_sees_their_own_configuration(): void
    {
        $user = User::factory()->create();

        UserAiCredential::factory()->for($user)->create([
            'encrypted_api_key' => self::KEY,
            'key_hint' => '7F2A',
        ]);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/settings/ai')
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.key_hint', '7F2A')
            ->assertJsonPath('data.providers.gemini.configured', true);
    }

    /**
     * Isolation is asserted in its own test, with a single `actingAs()`.
     *
     * Doing both users in one test method silently passes the WRONG user: the
     * auth guard keeps the first resolved user for the rest of the test, so the
     * second request is answered as user A. That reads as a real credential
     * leak and is not one — which is exactly the kind of false alarm worth
     * designing the test around rather than around.
     */
    public function test_another_users_credential_is_never_visible(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        UserAiCredential::factory()->for($owner)->create([
            'encrypted_api_key' => self::KEY,
            'key_hint' => '7F2A',
        ]);

        $response = $this->actingAs($other, 'web')->getJson('/api/v1/settings/ai');

        $response->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.key_hint', null);

        $this->assertStringNotContainsString(
            '7F2A',
            $response->getContent(),
            "Another user's key hint leaked into the response."
        );
    }

    public function test_saving_a_key_does_not_touch_another_users_row(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $bCredential = UserAiCredential::factory()->for($userB)->create([
            'encrypted_api_key' => self::KEY,
            'key_hint' => '7F2A',
        ]);

        $this->actingAs($userA, 'web')
            ->putJson('/api/v1/settings/ai', [
                'provider' => 'gemini',
                'api_key' => 'AIzaSyDIFFERENTkeyAAAAAAAA9999',
            ])
            ->assertOk();

        $this->assertSame(1, $userA->aiCredentials()->count());
        $this->assertSame(1, $userB->aiCredentials()->count());
        $this->assertSame('7F2A', $bCredential->fresh()->key_hint, 'B\'s key was overwritten.');
    }

    // ------------------------------------------------------------ encryption

    public function test_the_key_is_encrypted_in_the_database(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/settings/ai', ['provider' => 'gemini', 'api_key' => self::KEY])
            ->assertOk();

        // Read the RAW column, bypassing every cast.
        $raw = DB::table('user_ai_credentials')
            ->where('user_id', $user->id)
            ->value('encrypted_api_key');

        $this->assertIsString($raw);
        $this->assertNotSame(self::KEY, $raw, 'The API key must not be stored in plaintext.');
        $this->assertStringNotContainsString(
            'AIzaSyTESTKEY',
            $raw,
            'No recognisable fragment of the key may survive into the column.'
        );

        // And it is genuinely decryptable, so this is encryption and not
        // accidental corruption.
        $this->assertSame(self::KEY, Crypt::decryptString($raw));
    }

    public function test_the_full_key_never_appears_in_any_api_response(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/settings/ai', ['provider' => 'gemini', 'api_key' => self::KEY])
            ->assertOk();

        $responses = [
            $this->actingAs($user, 'web')->getJson('/api/v1/settings/ai'),
            $this->actingAs($user, 'web')->putJson('/api/v1/settings/ai', ['mode' => 'automatic']),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
            $this->assertStringNotContainsString(
                self::KEY,
                $response->getContent(),
                'A full API key leaked into an API response.'
            );
        }
    }

    public function test_the_key_is_never_written_to_the_log(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/settings/ai', ['provider' => 'gemini', 'api_key' => self::KEY])
            ->assertOk();

        $logPath = storage_path('logs/laravel.log');
        $contents = is_file($logPath) ? (string) file_get_contents($logPath) : '';

        $this->assertStringNotContainsString(self::KEY, $contents);
    }

    // ----------------------------------------------------------- persistence

    public function test_the_key_survives_logout_and_a_fresh_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/settings/ai', ['provider' => 'gemini', 'api_key' => self::KEY])
            ->assertOk();

        // Log out, then log back in as a genuinely new request cycle.
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('user_ai_credentials', 1);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->actingAs($user->fresh(), 'web')
            ->getJson('/api/v1/settings/ai')
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.key_hint', '7F2A');
    }

    public function test_the_key_is_stored_against_the_account_not_the_device(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/settings/ai', ['provider' => 'gemini', 'api_key' => self::KEY])
            ->assertOk();

        // A second "device" is simply a new request with the same account.
        $fresh = User::findOrFail($user->id);

        $this->actingAs($fresh, 'web')
            ->getJson('/api/v1/settings/ai')
            ->assertJsonPath('data.configured', true);

        $this->assertSame(
            self::KEY,
            $this->resolver()->personalKey($fresh, 'gemini'),
            'The key must be readable from the account, from anywhere.'
        );
    }

    public function test_deleting_the_key_removes_it_and_resets_a_personal_mode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/settings/ai', ['provider' => 'gemini', 'api_key' => self::KEY])
            ->assertOk();

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/settings/ai', ['mode' => 'personal'])
            ->assertOk();

        $this->assertSame(AiMode::Personal, $user->fresh()->aiMode());

        $this->actingAs($user, 'web')
            ->deleteJson('/api/v1/settings/ai/gemini')
            ->assertOk()
            ->assertJsonPath('data.removed', true);

        $this->assertDatabaseCount('user_ai_credentials', 0);
        $this->assertNull($this->resolver()->personalKey($user->fresh(), 'gemini'));

        // Left in `personal` with no key, the next AI call would fail with an
        // error they cannot act on — so the mode resets.
        $this->assertSame(AiMode::Automatic, $user->fresh()->aiMode());
    }

    public function test_deleting_a_key_that_was_never_saved_is_harmless(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->deleteJson('/api/v1/settings/ai/gemini')
            ->assertOk()
            ->assertJsonPath('data.removed', false)
            ->assertJsonPath('data.message', 'No Gemini API key was saved.');
    }

    // ------------------------------------------------------------ validation

    public function test_a_short_key_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/settings/ai', ['provider' => 'gemini', 'api_key' => 'short'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['api_key']);

        $this->assertDatabaseCount('user_ai_credentials', 0);
    }

    public function test_a_key_containing_whitespace_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/settings/ai', [
                'provider' => 'gemini',
                'api_key' => 'AIzaSyTESTKEY 7F2A',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['api_key']);
    }

    public function test_an_unsupported_provider_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/settings/ai', ['provider' => 'anthropic', 'api_key' => self::KEY])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['provider']);
    }

    public function test_an_unknown_mode_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/settings/ai', ['mode' => 'yolo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);
    }

    public function test_personal_mode_requires_a_key_to_exist(): void
    {
        $user = User::factory()->create();

        // Choosing "always use my own quota" with no key is a contradiction;
        // it must fail now, not on the user's next AI request.
        $this->actingAs($user, 'web')
            ->putJson('/api/v1/settings/ai', ['mode' => 'personal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['api_key']);

        $this->assertSame(AiMode::Automatic, $user->fresh()->aiMode());
    }

    public function test_saving_a_key_replaces_the_previous_one(): void
    {
        $user = User::factory()->create();
        $resolver = $this->resolver();

        $resolver->store($user, 'gemini', 'AIzaSyFIRSTkey1111111111AAAA');
        $resolver->store($user, 'gemini', 'AIzaSySECONDkey22222222BBBB');

        $this->assertSame(1, $user->aiCredentials()->count(), 'Upsert, not a second row.');
        $this->assertSame('BBBB', $user->aiCredentials()->first()->key_hint);
        $this->assertSame('AIzaSySECONDkey22222222BBBB', $resolver->personalKey($user, 'gemini'));
    }

    public function test_a_disabled_credential_is_not_used(): void
    {
        $user = User::factory()->create();
        $resolver = $this->resolver();

        $credential = $resolver->store($user, 'gemini', self::KEY);
        $this->assertNotNull($resolver->personalKey($user, 'gemini'));

        $credential->forceFill(['enabled' => false])->save();

        $this->assertNull(
            $resolver->personalKey($user, 'gemini'),
            'A disabled credential must be invisible to the resolver.'
        );
        $this->assertFalse($resolver->hasPersonalKey($user, 'gemini'));
    }
}
