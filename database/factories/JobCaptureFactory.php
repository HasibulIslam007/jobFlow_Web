<?php

namespace Database\Factories;

use App\Enums\JobCaptureStatus;
use App\Enums\JobCaptureType;
use App\Models\JobCapture;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobCapture>
 */
class JobCaptureFactory extends Factory
{
    protected $model = JobCapture::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(JobCaptureType::cases()),
            'content' => fake()->paragraph(),
            'file_path' => null,
            'status' => JobCaptureStatus::Pending,
            'error_message' => null,
            'processed_at' => null,
        ];
    }
}
