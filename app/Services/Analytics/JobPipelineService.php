<?php

namespace App\Services\Analytics;

use App\Enums\JobStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Job pipeline analysis (Phase 6.8).
 *
 * WHERE THIS SITS, AND WHY IT IS NOT THE SAME NUMBER AS application_metrics
 * ------------------------------------------------------------------------
 * The `pipeline` block describes the user's *tracked jobs* (`user_jobs.status`)
 * — how their search is distributed across saved → preparing → applied →
 * interview → offer → rejected.
 *
 * `application_metrics` (ApplicationAnalyticsService) describes *application
 * records* — how many they sent, and what came back.
 *
 * These are genuinely different populations: a user can have 40 saved jobs and
 * 4 applications, or 4 jobs with 3 applications each. Showing both under the
 * same word "applied" would be actively misleading, so the two blocks are kept
 * apart and the UI labels them apart too. One grouped query, no N+1.
 */
class JobPipelineService
{
    /**
     * Stage tallies for the user's jobs, zero-filled across every status.
     *
     * @return array<string, int>
     */
    public function forUser(User $user): array
    {
        $rows = DB::table('user_jobs')
            ->where('user_id', $user->getKey())
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $pipeline = [];

        foreach (JobStatus::cases() as $status) {
            $pipeline[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        return $pipeline;
    }
}
