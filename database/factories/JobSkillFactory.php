<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\JobSkill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobSkill>
 */
class JobSkillFactory extends Factory
{
    protected $model = JobSkill::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'skill_name' => fake()->randomElement([
                'PHP', 'Laravel', 'TypeScript', 'Next.js', 'React', 'PostgreSQL',
                'Docker', 'Tailwind CSS', 'Redis', 'Python', 'AWS', 'REST API',
            ]),
        ];
    }
}
