<?php

namespace Tests\Feature\Notifications;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationType;
use App\Models\Application;
use App\Models\Job;
use App\Models\Notification;
use App\Models\Resume;
use App\Models\User;
use App\Services\Notifications\Generators\AiInsightNotificationGenerator;
use App\Services\Notifications\Generators\DeadlineNotificationGenerator;
use App\Services\Notifications\Generators\FollowUpNotificationGenerator;
use App\Services\Notifications\Generators\InterviewNotificationGenerator;
use App\Services\Notifications\Generators\ResumeNotificationGenerator;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6.9 — Notification Intelligence System.
 *
 * Two things are defended hardest here:
 *
 *  1. ISOLATION. Generators walk *every* user, so a scoping bug would spray
 *     one person's deadlines and resume scores into another person's bell.
 *     Several tests seed a second user with loud data and assert it produces
 *     nothing for the first.
 *
 *  2. IDEMPOTENCE. The sweep runs daily and may overlap with itself or be
 *     fired by hand. A generator that re-notifies every run would make the
 *     product untrustworthy within a week.
 */
class NotificationIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    // ------------------------------------------------------------- API layer

    public function test_guest_cannot_read_notifications(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
        $this->getJson('/api/v1/notifications/unread-count')->assertStatus(401);
        $this->patchJson('/api/v1/notifications/read-all')->assertStatus(401);
    }

    public function test_unread_count_endpoint_returns_the_badge_number(): void
    {
        $user = User::factory()->create();

        Notification::factory()->count(3)->for($user)->create(['read_at' => null]);
        Notification::factory()->for($user)->create(['read_at' => now()]);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 3);
    }

    public function test_mark_all_read_clears_only_that_users_unread_rows(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Notification::factory()->count(4)->for($user)->create(['read_at' => null]);
        Notification::factory()->for($user)->create(['read_at' => now()]);
        Notification::factory()->count(2)->for($other)->create(['read_at' => null]);

        $this->actingAs($user, 'web')->patchJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 4)
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(0, $this->service()->unreadCount($user));
        // The neighbour keeps their unread badge.
        $this->assertSame(2, $this->service()->unreadCount($other));
    }

    public function test_mark_all_read_is_a_no_op_when_nothing_is_unread(): void
    {
        $user = User::factory()->create();
        Notification::factory()->for($user)->create(['read_at' => now()]);

        $this->actingAs($user, 'web')
            ->patchJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 0)
            ->assertJsonPath('data.message', 'No unread notifications.');
    }

    public function test_user_cannot_read_another_users_notification(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $notification = Notification::factory()->for($other)->create();

        $this->actingAs($user, 'web')
            ->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertStatus(403);

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_notification_payload_exposes_action_url_and_metadata(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->for($user)->create();

        $this->service()->create(
            user: $user,
            type: NotificationType::FollowUp,
            title: 'Follow up',
            message: 'Message body',
            data: ['job_id' => $job->id, 'dedupe_key' => 'x'],
            actionUrl: "/jobs/{$job->id}",
        );

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.notifications.0.action_url', "/jobs/{$job->id}")
            ->assertJsonPath('data.notifications.0.data.job_id', $job->id)
            ->assertJsonPath('data.notifications.0.type', 'follow_up');
    }

    // -------------------------------------------------------- createIfMissing

    public function test_create_if_missing_dedupes_and_reports_what_it_did(): void
    {
        $user = User::factory()->create();
        $service = $this->service();

        $first = $service->createIfMissing(
            $user, NotificationType::FollowUp, 'Follow up', 'Message', 'follow_up:1:1'
        );
        $second = $service->createIfMissing(
            $user, NotificationType::FollowUp, 'Follow up', 'Message', 'follow_up:1:1'
        );

        $this->assertNotNull($first, 'First call must create.');
        $this->assertNull($second, 'Identical key must not create a second row.');
        $this->assertSame(1, $user->notifications()->count());

        // A different key is a different fact and must go through.
        $this->assertNotNull($service->createIfMissing(
            $user, NotificationType::FollowUp, 'Again', 'Message', 'follow_up:1:2'
        ));
        $this->assertSame(2, $user->notifications()->count());
    }

    public function test_dedupe_is_scoped_per_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $service = $this->service();

        $this->assertNotNull($service->createIfMissing(
            $user, NotificationType::Interview, 'A', 'B', 'shared-key'
        ));

        // The same key for a different user is that user's own notification.
        $this->assertNotNull($service->createIfMissing(
            $other, NotificationType::Interview, 'A', 'B', 'shared-key'
        ));
    }

    // ------------------------------------------------------------- generators

    public function test_deadline_generator_fires_within_three_days(): void
    {
        $user = User::factory()->create();

        Job::factory()->for($user)->create(['deadline' => now()->addDays(2)->toDateString()]);
        Job::factory()->for($user)->create(['deadline' => now()->toDateString()]);
        // Too far away, and already past — neither should notify.
        Job::factory()->for($user)->create(['deadline' => now()->addDays(20)->toDateString()]);
        Job::factory()->for($user)->create(['deadline' => now()->subDay()->toDateString()]);
        Job::factory()->for($user)->create(['deadline' => null]);

        $this->assertSame(2, app(DeadlineNotificationGenerator::class)->generateFor($user));
        $this->assertSame(2, $user->notifications()->count());

        $notification = $user->notifications()->first();
        $this->assertSame(NotificationType::DeadlineReminder, $notification->type);
        $this->assertSame('Application deadline approaching', $notification->title);
        $this->assertNotNull($notification->action_url);
    }

    public function test_deadline_generator_is_idempotent(): void
    {
        $user = User::factory()->create();
        Job::factory()->for($user)->create(['deadline' => now()->addDay()->toDateString()]);

        $generator = app(DeadlineNotificationGenerator::class);

        $this->assertSame(1, $generator->generateFor($user));
        $this->assertSame(0, $generator->generateFor($user), 'Re-running must not re-notify.');
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_deadline_generator_does_not_touch_another_users_jobs(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Job::factory()->count(5)->for($other)->create([
            'deadline' => now()->addDay()->toDateString(),
        ]);

        $this->assertSame(0, app(DeadlineNotificationGenerator::class)->generateFor($user));
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_follow_up_generator_fires_after_seven_quiet_days(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->for($user)->create();

        $this->application($user, $job, ApplicationStatus::Applied, 10);

        $this->assertSame(1, app(FollowUpNotificationGenerator::class)->generateFor($user));

        $notification = $user->notifications()->first();
        $this->assertSame(NotificationType::FollowUp, $notification->type);
        $this->assertStringContainsString($job->company, $notification->title);
        $this->assertSame("/jobs/{$job->id}", $notification->action_url);
        $this->assertSame(10, $notification->data['dormant_days']);
    }

    public function test_follow_up_generator_ignores_recent_and_answered_applications(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->for($user)->create();

        // Touched yesterday — nothing to chase.
        $this->application($user, $job, ApplicationStatus::Applied, 1);
        // Quiet, but already at interview — the ball is not with the user.
        $this->application($user, $job, ApplicationStatus::Interview, 30);

        $this->assertSame(0, app(FollowUpNotificationGenerator::class)->generateFor($user));
    }

    /**
     * The follow-up bucket is the subtle part: the same day must dedupe, but a
     * longer silence is a genuinely new and more urgent fact.
     */
    public function test_follow_up_dedupes_per_dormancy_bucket(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->for($user)->create();
        $application = $this->application($user, $job, ApplicationStatus::Applied, 10);

        $generator = app(FollowUpNotificationGenerator::class);

        $this->assertSame(1, $generator->generateFor($user));
        $this->assertSame(0, $generator->generateFor($user), 'Same dormancy → no re-notify.');

        // Two more weeks of silence crosses into the next bucket.
        $application->forceFill(['updated_at' => now()->subDays(24)])->save();

        $this->assertSame(1, $generator->generateFor($user), 'Longer silence → say it again.');
        $this->assertSame(2, $user->notifications()->count());
    }

    public function test_interview_generator_fires_once_per_interview(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->for($user)->create();
        $this->application($user, $job, ApplicationStatus::Interview, 3);

        $generator = app(InterviewNotificationGenerator::class);

        $this->assertSame(1, $generator->generateFor($user));
        $this->assertSame(0, $generator->generateFor($user), 'One-shot, not a weekly nag.');

        $notification = $user->notifications()->first();
        $this->assertSame(NotificationType::Interview, $notification->type);
        $this->assertSame('Prepare for your interview', $notification->title);
    }

    public function test_resume_generator_fires_below_the_threshold_only(): void
    {
        $user = User::factory()->create();

        Resume::factory()->for($user)->create(['ai_score' => 42]);
        Resume::factory()->for($user)->create(['ai_score' => 88]);
        // Unscored means "unknown", not "bad" — must not nag.
        Resume::factory()->for($user)->create(['ai_score' => null]);

        $this->assertSame(1, app(ResumeNotificationGenerator::class)->generateFor($user));
        $this->assertSame('Improve your resume', $user->notifications()->first()->title);
    }

    public function test_resume_generator_stays_quiet_while_the_band_is_unchanged(): void
    {
        $user = User::factory()->create();
        $resume = Resume::factory()->for($user)->create(['ai_score' => 41]);

        $generator = app(ResumeNotificationGenerator::class);

        $this->assertSame(1, $generator->generateFor($user));

        // 41 → 48 stays inside the 40s bucket: not a new fact.
        $resume->forceFill(['ai_score' => 48])->save();
        $this->assertSame(0, $generator->generateFor($user));

        // Dropping into the 30s band is a new, worse fact.
        $resume->forceFill(['ai_score' => 33])->save();
        $this->assertSame(1, $generator->generateFor($user));
    }

    public function test_ai_insight_generator_surfaces_a_real_skill_gap(): void
    {
        $user = User::factory()->create();

        // 4 jobs all demanding AWS; the resume does not list it.
        for ($i = 0; $i < 4; $i++) {
            Job::factory()->for($user)->create()
                ->skills()
                ->create(['skill_name' => 'AWS']);
        }

        $this->assertSame(1, app(AiInsightNotificationGenerator::class)->generateFor($user));

        $notification = $user->notifications()->first();
        $this->assertSame(NotificationType::AiInsight, $notification->type);
        $this->assertStringContainsString('AWS', $notification->message);
        $this->assertSame('/analytics', $notification->action_url);
    }

    public function test_ai_insight_generator_flags_an_inactive_search(): void
    {
        $user = User::factory()->create();
        $job = Job::factory()->for($user)->create();
        $this->application($user, $job, ApplicationStatus::Applied, 1);

        $this->assertSame(1, app(AiInsightNotificationGenerator::class)->generateFor($user));
        $this->assertStringContainsString(
            'activity is low',
            $user->notifications()->first()->title
        );
    }

    public function test_ai_insight_generator_stays_silent_without_evidence(): void
    {
        // No jobs, no applications, no resume: nothing true to say.
        $user = User::factory()->create();

        $this->assertSame(0, app(AiInsightNotificationGenerator::class)->generateFor($user));
        $this->assertSame(0, $user->notifications()->count());
    }

    /**
     * Regression: the generator used to fall through to the "low activity"
     * branch when the skill-gap notification was already delivered, so a second
     * sweep produced a *different* insight purely because it ran again. The
     * contract is one insight per underlying fact — never churn.
     */
    public function test_ai_insight_generator_does_not_fall_through_to_another_insight(): void
    {
        $user = User::factory()->create();

        // An actionable skill gap, plus a low application count, so both
        // branches would otherwise have fired.
        $gapJob = Job::factory()->for($user)->create();
        $gapJob->skills()->create(['skill_name' => 'Kubernetes']);

        $applicationJob = Job::factory()->for($user)->create();
        $this->application($user, $applicationJob, ApplicationStatus::Applied, 1);

        $generator = app(AiInsightNotificationGenerator::class);

        $this->assertSame(1, $generator->generateFor($user));
        $this->assertSame(0, $generator->generateFor($user), 'Re-run must add nothing.');
        $this->assertSame(1, $user->notifications()->count());

        // The delivered insight is the skill gap, not the activity one.
        $this->assertStringContainsString(
            'Kubernetes',
            $user->notifications()->first()->message
        );
    }

    // ---------------------------------------------------------------- command

    public function test_generate_command_sweeps_every_user_and_is_idempotent(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $jobA = Job::factory()->for($userA)->create(['deadline' => now()->addDay()->toDateString()]);
        $jobB = Job::factory()->for($userB)->create(['deadline' => now()->addDay()->toDateString()]);

        $this->assertSame(0, Notification::query()->count());

        $this->artisan('notifications:generate')->assertSuccessful();

        // One per user, each scoped to their own job.
        $this->assertSame(2, Notification::query()->count());
        $this->assertSame(1, $userA->notifications()->where('data->job_id', $jobA->id)->count());
        $this->assertSame(1, $userB->notifications()->where('data->job_id', $jobB->id)->count());

        $this->artisan('notifications:generate')->assertSuccessful();

        $this->assertSame(2, Notification::query()->count(), 'Re-run must be a no-op.');
    }

    public function test_generate_command_can_target_a_single_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Job::factory()->for($userA)->create(['deadline' => now()->addDay()->toDateString()]);
        Job::factory()->for($userB)->create(['deadline' => now()->addDay()->toDateString()]);

        $this->artisan('notifications:generate', ['--user' => $userA->id])->assertSuccessful();

        $this->assertSame(1, $userA->notifications()->count());
        $this->assertSame(0, $userB->notifications()->count());
    }

    public function test_the_daily_schedule_is_registered(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => $event->command ?? '')
            ->implode(' ');

        $this->assertStringContainsString('notifications:generate', $commands);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Create an application whose updated_at is `daysAgo` in the past, which
     * is what the dormancy rules actually read.
     */
    private function application(
        User $user,
        Job $job,
        ApplicationStatus $status,
        int $daysAgo,
    ): Application {
        $timestamp = now()->subDays($daysAgo);

        $application = Application::create([
            'job_id' => $job->id,
            'status' => $status,
            'applied_date' => $timestamp->toDateString(),
        ]);

        $application->forceFill([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->save();

        return $application;
    }
}
