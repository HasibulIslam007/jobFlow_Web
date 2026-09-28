<?php

namespace Database\Factories;

use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Job>
 */
class JobFactory extends Factory
{
    protected $model = Job::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->jobTitle(),
            'company' => fake()->company(),
            'description' => fake()->paragraphs(3, true),
            'location' => fake()->city().', '.fake()->country(),
            'salary' => '$'.fake()->numberBetween(80, 160).'k - $'.fake()->numberBetween(170, 240).'k',
            'deadline' => fake()->dateTimeBetween('+1 week', '+2 months')->format('Y-m-d'),
            'source_type' => fake()->randomElement(['manual', 'url', 'pdf', 'image']),
            'source_url' => fake()->url(),
            'status' => fake()->randomElement(JobStatus::cases()),
        ];
    }
}
