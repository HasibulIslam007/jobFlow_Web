<?php

namespace App\Services\Notifications\Generators;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationType;
use App\Models\Application;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;

/**
 * FOLLOW-UP REMINDERS — an application that has gone quiet.
 *
 * RULE
 * ----
 * An application in an "outbound" stage (`applied`) that has not been touched
 * for FOLLOW_UP_AFTER_DAYS fires once. Stages that already imply contact are
 * skipped: an interview or an offer means the ball is not in the user's court.
 *
 * DEDUPE KEY
 * ----------
 * `follow_up:{application_id}:{bucket}` where bucket is the number of whole
 * weeks the application has been dormant. That is deliberate:
 *
 *   · re-running the command the same day is a no-op (same bucket)
 *   · but an application that goes quiet for 2 weeks, then 4, then 6 is
 *     genuinely a new, more urgent fact each time, and the user should be
 *     told again rather than being permanently muted after the first nudge.
 *
 * A flat `follow_up:{id}` key would satisfy "no duplicates" while silently
 * never reminding anyone a second time — which is the failure mode this
 * bucketing exists to avoid.
 */
class FollowUpNotificationGenerator implements NotificationGenerator
{
    /** Days of silence before a follow-up is suggested. */
    public const FOLLOW_UP_AFTER_DAYS = 7;

    public function __construct(private readonly NotificationService $notifications) {}

    public function name(): string
    {
        return 'follow_up';
    }

    public function generateFor(User $user): int
    {
        $cutoff = Carbon::now()->subDays(self::FOLLOW_UP_AFTER_DAYS);

        $stale = Application::query()
            ->join('user_jobs', 'user_jobs.id', '=', 'applications.job_id')
            ->where('user_jobs.user_id', $user->id)
            ->where('applications.status', ApplicationStatus::Applied->value)
            ->where('applications.updated_at', '<=', $cutoff)
            // One query, not a per-application lookup: the job's title and
            // company are needed for every row we are about to notify about.
            ->join('user_jobs as j', 'j.id', '=', 'applications.job_id')
            ->get([
                'applications.id',
                'applications.job_id',
                'applications.updated_at',
                'j.title as job_title',
                'j.company as job_company',
            ]);

        $created = 0;

        foreach ($stale as $application) {
            $dormantDays = (int) $application->updated_at->diffInDays(Carbon::now());
            $bucket = intdiv($dormantDays, 7);

            // Below one full week of silence there is nothing to say, even if
            // the record technically crossed the cutoff mid-day.
            if ($bucket < 1) {
                continue;
            }

            $result = $this->notifications->createIfMissing(
                user: $user,
                type: NotificationType::FollowUp,
                title: 'Follow up with '.$application->job_company,
                message: "Your application for {$application->job_title} at {$application->job_company} "
                    .'has had no update for about '.$this->humaniseDays($dormantDays)
                    .'. A short follow-up email is usually worth sending.',
                dedupeKey: "follow_up:{$application->id}:{$bucket}",
                data: [
                    'application_id' => $application->id,
                    'job_id' => $application->job_id,
                    'job_title' => $application->job_title,
                    'company' => $application->job_company,
                    'dormant_days' => $dormantDays,
                ],
                actionUrl: "/jobs/{$application->job_id}",
            );

            if ($result !== null) {
                $created++;
            }
        }

        return $created;
    }

    private function humaniseDays(int $days): string
    {
        if ($days < 14) {
            return $days.' days';
        }

        $weeks = intdiv($days, 7);

        return $weeks.' weeks';
    }
}
