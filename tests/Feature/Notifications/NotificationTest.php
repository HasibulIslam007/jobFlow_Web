<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Enums\ReminderStatus;
use App\Mail\DeadlineReminderMail;
use App\Models\Job;
use App\Models\Notification;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_notification(): void
    {
        $user = User::factory()->create();

        $notification = $user->notifications()->create([
            'type' => NotificationType::System,
            'title' => 'Welcome aboard',
            'message' => 'Your workspace is ready.',
            'data' => ['source' => 'test'],
        ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'user_id' => $user->id,
            'type' => NotificationType::System->value,
            'title' => 'Welcome aboard',
        ]);
    }

    public function test_user_can_view_notifications(): void
    {
        $user = User::factory()->create();
        Notification::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonCount(3, 'data.notifications')
            ->assertJsonStructure([
                'data' => [
                    'notifications' => [
                        ['id', 'type', 'title', 'message', 'read_at', 'created_at'],
                    ],
                    'unread_count',
                ],
                'meta' => ['request_id', 'generated_at'],
            ]);
    }

    public function test_unread_count_works(): void
    {
        $user = User::factory()->create();
        Notification::factory()->count(2)->create(['user_id' => $user->id]);
        Notification::factory()->count(3)->read()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/notifications');

        $response->assertOk()->assertJsonPath('data.unread_count', 2);
    }

    public function test_mark_notification_as_read(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->create(['user_id' => $user->id]);

        $this->assertNull($notification->read_at);

        $response = $this->actingAs($user, 'web')
            ->patchJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertOk()
            ->assertJsonPath('data.notification.id', $notification->id)
            ->assertJsonPath('data.unread_count', 0);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_user_isolation_works(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $notification = Notification::factory()->create(['user_id' => $owner->id]);

        // Intruder's own list stays empty — owner rows never leak.
        $this->actingAs($intruder, 'web')->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data.notifications')
            ->assertJsonPath('data.unread_count', 0);

        // Direct access to someone else's notification is forbidden.
        $this->actingAs($intruder, 'web')
            ->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertStatus(403);

        $this->actingAs($intruder, 'web')
            ->deleteJson("/api/v1/notifications/{$notification->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'read_at' => null,
        ]);
    }

    public function test_deadline_command_creates_notification(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $job = Job::factory()->create([
            'user_id' => $user->id,
            'title' => 'Senior Backend Engineer',
            'company' => 'Google',
            'deadline' => now()->addDays(3)->toDateString(),
        ]);
        Reminder::factory()->create([
            'job_id' => $job->id,
            'user_id' => $user->id,
            'status' => ReminderStatus::Pending,
            'notification_days' => 3,
            'reminder_date' => now()->startOfDay(),
        ]);

        $this->artisan('reminders:send')
            ->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => NotificationType::DeadlineReminder->value,
        ]);

        $notification = Notification::where('user_id', $user->id)->first();
        $this->assertNotNull($notification);
        $this->assertSame($job->id, $notification->data['job_id']);
        $this->assertStringContainsString('Senior Backend Engineer', $notification->message);

        $this->assertDatabaseHas('reminders', [
            'id' => $job->reminders()->first()->id,
            'status' => ReminderStatus::Sent->value,
        ]);

        // MailFake records the mailable before the envelope is applied,
        // so assert the subject through envelope() instead of ->subject.
        Mail::assertSent(DeadlineReminderMail::class, function (DeadlineReminderMail $mail) use ($user): bool {
            return $mail->hasTo($user->email)
                && $mail->envelope()->subject === 'Your Job Application Deadline Is Coming';
        });
    }

    public function test_duplicate_reminders_prevented(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $job = Job::factory()->create([
            'user_id' => $user->id,
            'deadline' => now()->addDays(3)->toDateString(),
        ]);
        Reminder::factory()->create([
            'job_id' => $job->id,
            'user_id' => $user->id,
            'status' => ReminderStatus::Pending,
            'notification_days' => 3,
            'reminder_date' => now()->startOfDay(),
        ]);

        // Two sweeps in a row (scheduler overlap / manual re-run).
        $this->artisan('reminders:send')->assertSuccessful();
        $this->artisan('reminders:send')->assertSuccessful();

        $this->assertSame(1, Notification::where('user_id', $user->id)->count());
        Mail::assertSent(DeadlineReminderMail::class, 1);
    }

    public function test_command_skips_jobs_without_deadline(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $user->id, 'deadline' => null]);
        Reminder::factory()->create([
            'job_id' => $job->id,
            'user_id' => $user->id,
            'status' => ReminderStatus::Pending,
            'notification_days' => 3,
            'reminder_date' => now()->startOfDay(),
        ]);

        $this->artisan('reminders:send')->assertSuccessful();

        $this->assertSame(0, Notification::count());
        Mail::assertNothingSent();
    }

    public function test_user_can_create_reminder_for_their_job(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create([
            'user_id' => $user->id,
            'deadline' => '2026-10-10',
        ]);

        $response = $this->actingAs($user, 'web')->postJson(
            "/api/v1/jobs/{$job->id}/reminders",
            ['notification_days' => 3],
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.reminder.notification_days', 3)
            ->assertJsonPath('data.reminder.status', 'pending');

        $this->assertDatabaseHas('reminders', [
            'job_id' => $job->id,
            'user_id' => $user->id,
            'notification_days' => 3,
            'status' => ReminderStatus::Pending->value,
        ]);

        // reminder_date = deadline - notification_days = Oct 7.
        $this->assertSame(
            '2026-10-07',
            Reminder::where('job_id', $job->id)->first()->reminder_date->toDateString(),
        );
    }

    public function test_reminder_resave_updates_instead_of_duplicating(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create([
            'user_id' => $user->id,
            'deadline' => '2026-10-10',
        ]);

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/jobs/{$job->id}/reminders", ['notification_days' => 1])
            ->assertStatus(201);

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/jobs/{$job->id}/reminders", ['notification_days' => 7])
            ->assertStatus(201);

        $this->assertDatabaseCount('reminders', 1);
        $this->assertDatabaseHas('reminders', [
            'job_id' => $job->id,
            'notification_days' => 7,
        ]);
    }

    public function test_reminder_requires_job_deadline(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $user->id, 'deadline' => null]);

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/jobs/{$job->id}/reminders", ['notification_days' => 3])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    }

    public function test_reminder_validation_works(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $user->id, 'deadline' => '2026-10-10']);

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/jobs/{$job->id}/reminders", ['notification_days' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notification_days']);

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/jobs/{$job->id}/reminders", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notification_days']);
    }

    public function test_user_cannot_set_reminder_on_someone_elses_job(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $job = Job::factory()->create(['user_id' => $owner->id, 'deadline' => '2026-10-10']);

        $this->actingAs($intruder, 'web')
            ->postJson("/api/v1/jobs/{$job->id}/reminders", ['notification_days' => 3])
            ->assertStatus(403);

        $this->assertDatabaseCount('reminders', 0);
    }

    public function test_guest_cannot_access_notification_endpoints(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->create(['user_id' => $user->id]);
        $job = Job::factory()->create(['user_id' => $user->id, 'deadline' => '2026-10-10']);

        $this->getJson('/api/v1/notifications')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');

        $this->patchJson("/api/v1/notifications/{$notification->id}/read")->assertStatus(401);
        $this->deleteJson("/api/v1/notifications/{$notification->id}")->assertStatus(401);
        $this->postJson("/api/v1/jobs/{$job->id}/reminders", ['notification_days' => 3])->assertStatus(401);
    }
}
