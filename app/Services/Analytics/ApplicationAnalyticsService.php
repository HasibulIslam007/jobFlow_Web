<?php

namespace App\Services\Analytics;

use App\Enums\ApplicationStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Application funnel analytics for one user (Phase 6.8).
 *
 * WHY EVERY QUERY JOINS user_jobs
 * -------------------------------
 * The `applications` table has no `user_id` — an application hangs off a job
 * (`job_id -> user_jobs.id -> user_jobs.user_id`). Scoping by the job join is
 * therefore the only way to isolate a user's rows, and getting it wrong would
 * leak another user's application funnel. Every method here filters through
 * that join; none of them accepts an unfiltered builder from a caller.
 *
 * Aggregation happens in SQL (one grouped query for all six stages) rather
 * than by loading rows and counting in PHP.
 */
class ApplicationAnalyticsService
{
    /**
     * Window used to decide whether the user is *currently* active, as opposed
     * to having applied at some point in the past.
     */
    public const ACTIVE_WINDOW_DAYS = 30;

    /**
     * Conversion funnel + rates for the given user.
     *
     * RATE DEFINITIONS (percentages, rounded to whole numbers)
     * ------------------------------------------------------
     *   response_rate  — an employer engaged at all: interview + offer +
     *                     rejected, over the total. A rejection counts as a
     *                     response; the employer did get back to you.
     *   interview_rate — sitting at interview, over the total.
     *   offer_rate     — sitting at offer, over the total.
     *
     * All three use the *total* application count as the denominator, so an
     * application still in `saved`/`preparing` counts against the rate — that
     * is intentional: it is what makes "I have 20 applications but no
     * interviews" read as 0%, which is the truth.
     *
     * @return array{
     *     total_applications: int,
     *     response_rate: int,
     *     interview_rate: int,
     *     offer_rate: int,
     *     pipeline: array<string, int>,
     *     recent_applications: int,
     *     progressed: int
     * }
     */
    public function forUser(User $user): array
    {
        $counts = $this->statusCounts($user);

        $total = array_sum($counts);

        $interview = $counts[ApplicationStatus::Interview->value] ?? 0;
        $offer = $counts[ApplicationStatus::Offer->value] ?? 0;
        $rejected = $counts[ApplicationStatus::Rejected->value] ?? 0;

        $percentage = fn (int $part): int => $total > 0
            ? (int) round(($part / $total) * 100)
            : 0;

        // Everything past `applied` — the numerator behind response_rate.
        $responded = $interview + $offer + $rejected;

        // "Reached an interview or better" — the conversion factor used by the
        // career score, which is cumulative by nature.
        $progressed = $interview + $offer;

        return [
            'total_applications' => $total,
            'response_rate' => $percentage($responded),
            'interview_rate' => $percentage($interview),
            'offer_rate' => $percentage($offer),
            'recent_applications' => $this->recentCount($user),
            'progressed' => $progressed,
            'average_days_to_response' => $this->averageDaysToResponse($user),
        ];
    }

    /**
     * Mean days between applying and the application moving past `applied`.
     *
     * HONESTY NOTE — this is a proxy, and the UI says so.
     * ------------------------------------------------
     * The schema stores no "responded_at" column. The only later timestamp on
     * an application is `updated_at`, so for anything that progressed past
     * `applied` this measures `updated_at - applied_date`: how long the
     * record sat before it last changed. For an interview or offer that is a
     * good proxy for response time; for a rejection whose notes were edited a
     * month later it over-reports. It is still derived from real records
     * rather than invented, which is the bar that matters — and returning
     * null (no progressed applications) is more honest than returning 0.
     */
    private function averageDaysToResponse(User $user): ?float
    {
        $days = DB::table('applications')
            ->join('user_jobs', 'user_jobs.id', '=', 'applications.job_id')
            ->where('user_jobs.user_id', $user->getKey())
            ->whereNotNull('applications.applied_date')
            ->whereIn('applications.status', [
                ApplicationStatus::Interview->value,
                ApplicationStatus::Offer->value,
                ApplicationStatus::Rejected->value,
            ])
            // `applied_date` is a DATE and `updated_at` a TIMESTAMP. Comparing
            // them directly folds the time of day into the difference, so both
            // sides are cast to DATE first — otherwise a record applied at
            // 09:00 and touched again at 17:00 the same day reads as 0.33 days.
            //
            // In PostgreSQL `date - date` already yields a whole number of
            // days, so it is averaged directly. Wrapping it in DATE_PART would
            // be wrong: date_part() takes an interval, not an integer.
            ->selectRaw(
                'AVG(applications.updated_at::date - applications.applied_date::date) as avg_days'
            )
            ->value('avg_days');

        return $days === null ? null : round((float) $days, 1);
    }

    /**
     * Stage tallies for this user, keyed by status value.
     *
     * @return array<string, int>
     */
    private function statusCounts(User $user): array
    {
        $rows = DB::table('applications')
            ->join('user_jobs', 'user_jobs.id', '=', 'applications.job_id')
            ->where('user_jobs.user_id', $user->getKey())
            ->select('applications.status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('applications.status')
            ->pluck('aggregate', 'status');

        $counts = [];

        foreach ($rows as $status => $aggregate) {
            $counts[$status] = (int) $aggregate;
        }

        return $counts;
    }

    /**
     * Applications created inside the activity window.
     *
     * `applications.created_at` is the only recency signal the schema offers —
     * there is no status-change history table.
     */
    private function recentCount(User $user): int
    {
        return (int) DB::table('applications')
            ->join('user_jobs', 'user_jobs.id', '=', 'applications.job_id')
            ->where('user_jobs.user_id', $user->getKey())
            ->where('applications.created_at', '>=', now()->subDays(self::ACTIVE_WINDOW_DAYS))
            ->count();
    }

    /**
     * Zero-filled stage tallies for the *application* set. The job-status
     * pipeline lives in JobPipelineService.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    public function stageCounts(User $user): array
    {
        return $this->completePipeline($this->statusCounts($user));
    }

    /**
     * Zero-filled stages, so an empty workspace still returns every key.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function completePipeline(array $counts): array
    {
        $pipeline = [];

        foreach (ApplicationStatus::cases() as $status) {
            $pipeline[$status->value] = $counts[$status->value] ?? 0;
        }

        return $pipeline;
    }
}
