<?php

namespace App\Services\Notifications\Generators;

use App\Enums\NotificationType;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DEADLINE APPROACHING — jobs closing soon.
 *
 * RELATIONSHIP TO THE EXISTING REMINDER FLOW
 * ------------------------------------------
 * `DeadlineReminderService` (Phase 5.7) fires when a user has *explicitly set
 * a reminder* on a job, and it also sends email. This generator covers the
 * other case: a saved job with a real deadline and NO reminder configured.
 * Without it, the most time-critical data in the product — a closing date the
 * user typed in themselves — would only warn them if they had also opted into
 * a reminder.
 *
 * Both are safe to run together: they use different dedupe keys
 * (`deadline_nudge:{job_id}:{days_left}` vs the reminder's `reminder_id`).
 *
 * DEDUPE KEY
 * ----------
 * `deadline_nudge:{job_id}:{days_left}`. The days-remaining is part of the key
 * so a 3-day warning and a 1-day warning are genuinely different facts, while
 * re-running the sweep the same day stays a no-op.
 */
class DeadlineNotificationGenerator implements NotificationGenerator
{
    /** Warn when a deadline is this close or closer (and not already past). */
    public const WITHIN_DAYS = 3;

    public function __construct(private readonly NotificationService $notifications) {}

    public function name(): string
    {
        return 'deadline';
    }

    public function generateFor(User $user): int
    {
        $today = Carbon::today();

        $closing = DB::table('user_jobs')
            ->where('user_id', $user->getKey())
            ->whereNotNull('deadline')
            ->whereDate('deadline', '>=', $today->toDateString())
            ->whereDate('deadline', '<=', $today->copy()->addDays(self::WITHIN_DAYS)->toDateString())
            ->orderBy('deadline')
            ->get(['id', 'title', 'company', 'deadline']);

        $created = 0;

        foreach ($closing as $job) {
            $deadline = Carbon::parse($job->deadline)->startOfDay();
            $daysLeft = (int) $today->diffInDays($deadline);
            $label = match (true) {
                $daysLeft === 0 => 'today',
                $daysLeft === 1 => 'tomorrow',
                default => "in {$daysLeft} days",
            };

            $result = $this->notifications->createIfMissing(
                user: $user,
                type: NotificationType::DeadlineReminder,
                title: 'Application deadline approaching',
                message: "{$job->title} at {$job->company} closes {$label} "
                    .'('.$deadline->format('j F Y').'). Make sure your application is in.',
                dedupeKey: "deadline_nudge:{$job->id}:{$daysLeft}",
                data: [
                    'job_id' => $job->id,
                    'job_title' => $job->title,
                    'company' => $job->company,
                    'deadline' => $deadline->toDateString(),
                    'days_left' => $daysLeft,
                ],
                actionUrl: "/jobs/{$job->id}",
            );

            if ($result !== null) {
                $created++;
            }
        }

        return $created;
    }
}
