<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'status' => fake()->randomElement(ApplicationStatus::cases()),
            'notes' => fake()->sentence(),
            'applied_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
        ];
    }
}
