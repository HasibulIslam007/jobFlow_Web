<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserAiCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAiCredential>
 */
class UserAiCredentialFactory extends Factory
{
    protected $model = UserAiCredential::class;

    /**
     * The plaintext is the value that goes through the `encrypted` cast on
     * write, so the row on disk holds ciphertext only.
     */
    public const SAMPLE_KEY = 'AIzaSyTESTKEYdontcommit7F2A';

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'gemini',
            'encrypted_api_key' => self::SAMPLE_KEY,
            'key_hint' => '7F2A',
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
