<?php

namespace Database\Factories;

use App\Enums\ReminderStatus;
use App\Models\Job;
use App\Models\Reminder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    protected $model = Reminder::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'user_id' => null,
            'reminder_date' => fake()->dateTimeBetween('+1 day', '+1 month'),
            'sent_at' => null,
            'status' => fake()->randomElement(ReminderStatus::cases()),
            'notification_days' => fake()->randomElement([1, 3, 5, 7]),
        ];
    }
}
