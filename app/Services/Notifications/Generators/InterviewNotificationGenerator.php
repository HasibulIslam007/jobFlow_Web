<?php

namespace App\Services\Notifications\Generators;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationType;
use App\Models\Application;
use App\Models\User;
use App\Services\Notifications\NotificationService;

/**
 * INTERVIEW PREPARATION REMINDERS.
 *
 * RULE
 * ----
 * An application sitting at `interview` gets one preparation nudge. The schema
 * stores no interview date, so this fires on *being at that stage* rather than
 * on a schedule — which is the only honest signal available. It would be
 * dishonest to claim "your interview is tomorrow" from a status column.
 *
 * DEDUPE KEY
 * ----------
 * `interview:{application_id}`. Unlike follow-ups this is a one-shot: the user
 * either prepares or they do not, and re-nagging every week for a stage they
 * are still sitting in is noise, not signal.
 */
class InterviewNotificationGenerator implements NotificationGenerator
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function name(): string
    {
        return 'interview';
    }

    public function generateFor(User $user): int
    {
        $interviews = Application::query()
            ->join('user_jobs', 'user_jobs.id', '=', 'applications.job_id')
            ->where('user_jobs.user_id', $user->id)
            ->where('applications.status', ApplicationStatus::Interview->value)
            ->join('user_jobs as j', 'j.id', '=', 'applications.job_id')
            ->get([
                'applications.id',
                'applications.job_id',
                'applications.updated_at',
                'j.title as job_title',
                'j.company as job_company',
            ]);

        $created = 0;

        foreach ($interviews as $application) {
            $result = $this->notifications->createIfMissing(
                user: $user,
                type: NotificationType::Interview,
                title: 'Prepare for your interview',
                message: "Your application for {$application->job_title} at {$application->job_company} "
                    .'is at interview stage. Re-read the job description and prepare two or three '
                    .'specific questions for the hiring manager.',
                dedupeKey: "interview:{$application->id}",
                data: [
                    'application_id' => $application->id,
                    'job_id' => $application->job_id,
                    'job_title' => $application->job_title,
                    'company' => $application->job_company,
                ],
                actionUrl: "/jobs/{$application->job_id}",
            );

            if ($result !== null) {
                $created++;
            }
        }

        return $created;
    }
}
