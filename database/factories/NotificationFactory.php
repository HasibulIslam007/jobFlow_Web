<?php

namespace Database\Factories;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => NotificationType::System,
            'title' => fake()->sentence(4),
            'message' => fake()->sentence(12),
            'data' => null,
            'read_at' => null,
        ];
    }

    /**
     * Notifications that have been opened already.
     */
    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => now()]);
    }

    /**
     * Deadline reminder notifications (the Phase 5.7 happy path).
     */
    public function deadlineReminder(): static
    {
        return $this->state(fn (): array => [
            'type' => NotificationType::DeadlineReminder,
            'title' => 'Deadline Tomorrow',
            'message' => 'Your application deadline is approaching.',
        ]);
    }
}
