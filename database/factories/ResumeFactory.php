<?php

namespace Database\Factories;

use App\Enums\ResumeStatus;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Resume>
 */
class ResumeFactory extends Factory
{
    protected $model = Resume::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->jobTitle().' Resume',
            'file_path' => 'resumes/'.fake()->year().'/'.fake()->month().'/'.fake()->uuid().'.pdf',
            'file_type' => 'pdf',
            'raw_text' => fake()->paragraphs(3, true),
            'status' => ResumeStatus::Completed,
            'ai_score' => fake()->numberBetween(50, 95),
        ];
    }

    /**
     * Resume uploaded but not yet processed by AI.
     */
    public function uploaded(): static
    {
        return $this->state(fn () => [
            'status' => ResumeStatus::Uploaded,
            'raw_text' => null,
            'ai_score' => null,
        ]);
    }
}
