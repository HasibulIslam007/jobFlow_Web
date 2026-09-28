<?php

namespace Database\Factories;

use App\Models\AIExtraction;
use App\Models\JobCapture;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIExtraction>
 */
class AIExtractionFactory extends Factory
{
    protected $model = AIExtraction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'job_capture_id' => JobCapture::factory(),
            'provider' => fake()->randomElement(['openai', 'gemini', 'fake']),
            'model' => fake()->randomElement(['gpt-4o-mini', 'gemini-1.5-flash', 'fake-model']),
            'input_tokens' => fake()->numberBetween(100, 2000),
            'output_tokens' => fake()->numberBetween(50, 800),
            'raw_response' => [
                'title' => fake()->jobTitle(),
                'company' => fake()->company(),
            ],
            'status' => 'success',
            'error_message' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => fake()->sentence(),
        ]);
    }
}
