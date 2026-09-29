<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'created_at',
                    ],
                    'token',
                    'authenticated',
                ],
                'meta' => [
                    'request_id',
                    'generated_at',
                ],
            ])
            ->assertJsonPath('data.user.name', 'Jane Doe')
            ->assertJsonPath('data.user.email', 'jane@example.com')
            ->assertJsonPath('data.authenticated', true);

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'name' => 'Jane Doe',
        ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertGuest();
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('CorrectPassword123!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'CorrectPassword123!',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'created_at',
                    ],
                    'token',
                    'authenticated',
                ],
                'meta' => [
                    'request_id',
                    'generated_at',
                ],
            ])
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'user@example.com')
            ->assertJsonPath('data.authenticated', true);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertGuest();
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/v1/auth/logout');

        $response->assertOk()
            ->assertJsonPath('data.authenticated', false)
            ->assertJsonPath('data.message', 'Successfully logged out.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_user_can_access_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Profile User',
            'email' => 'profile@example.com',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.name', 'Profile User')
            ->assertJsonPath('data.user.email', 'profile@example.com')
            ->assertJsonStructure([
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'created_at',
                    ],
                ],
                'meta' => [
                    'request_id',
                    'generated_at',
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_access_profile(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated')
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_bearer_token_can_access_a_protected_endpoint(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_revoked_token_cannot_access_protected_routes(): void
    {
        $user = User::factory()->create();
        $accessToken = $user->createToken('test');

        $accessToken->accessToken->delete();

        $this->withToken($accessToken->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_invalid_token_returns_unauthorized(): void
    {
        $this->withToken('invalid-token')
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_users_cannot_use_another_users_token(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertSame($user->id, $response->json('data.user.id'));
        $this->assertNotSame($otherUser->id, $response->json('data.user.id'));
    }

    public function test_invalid_login_fails(): void
    {
        User::factory()->create([
            'email' => 'valid@example.com',
            'password' => Hash::make('RealPassword123!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'valid@example.com',
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['email']);

        $this->assertGuest();
    }

    public function test_duplicate_email_registration_fails(): void
    {
        User::factory()->create([
            'email' => 'duplicate@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Second User',
            'email' => 'duplicate@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_requires_password_confirmation(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Mismatched User',
            'email' => 'mismatch@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'DifferentPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['password']);
    }
}
