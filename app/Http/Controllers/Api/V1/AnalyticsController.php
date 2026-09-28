<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnalyticsResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Analytics\ApplicationAnalyticsService;
use App\Services\Analytics\CareerInsightService;
use App\Services\Analytics\CareerScoreService;
use App\Services\Analytics\JobPipelineService;
use App\Services\Analytics\RoleAnalyticsService;
use App\Services\Analytics\SkillGapAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/analytics — AI Career Analytics & Insights (Phase 6.8).
 *
 * ONE aggregate request powers the whole analytics screen, mirroring how
 * DashboardController serves /dashboard: the frontend would otherwise fan out
 * across jobs, applications and resumes and recompute aggregates in the
 * browser.
 *
 * SCOPE
 * -----
 * Every service receives the authenticated `User` and scopes itself through
 * that user's rows. `applications`, `job_skills` and `job_resume_matches`
 * carry no `user_id`, so the services join through `user_jobs` /
 * `resumes` instead. There is no code path here that accepts an id from the
 * request, which is what makes cross-user leakage structurally impossible
 * rather than merely tested-for.
 *
 * QUERY BUDGET
 * ------------
 * Six grouped/counting queries, no per-row lookups and no N+1:
 *   1. application stage counts       4. required skill frequencies
 *   2. recent application count       5. resume analysis skills
 *   3. average resume score           6. job titles
 * Plus one job count used only to weight skill importance.
 */
class AnalyticsController extends Controller
{
    public function __invoke(
        Request $request,
        ApplicationAnalyticsService $applications,
        JobPipelineService $pipeline,
        CareerScoreService $scoreCalculator,
        SkillGapAnalyzer $skillGaps,
        RoleAnalyticsService $roles,
        CareerInsightService $insights,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $applicationAnalytics = $applications->forUser($user);
        $jobPipeline = $pipeline->forUser($user);

        $careerScore = $scoreCalculator->calculate($user, $applicationAnalytics);
        $skillGapList = $skillGaps->forUser($user);
        $topRoles = $roles->forUser($user);

        $insightList = $insights->generate([
            'total_applications' => $applicationAnalytics['total_applications'],
            'response_rate' => $applicationAnalytics['response_rate'],
            // The insight engine reasons about *applications*, so it receives
            // the application stage counts, not the job pipeline shown in the
            // UI. Two different populations, two different inputs.
            'pipeline' => $applications->stageCounts($user),
            'skill_gaps' => $skillGapList,
            'top_roles' => $topRoles,
            'has_resume' => $this->hasResume($user),
            'resume_score' => $this->resumeScore($user),
            'match_count' => $this->matchCount($user),
        ]);

        return ApiResponse::success(new AnalyticsResource([
            'career_score' => $careerScore,
            'application_metrics' => [
                'total_applications' => $applicationAnalytics['total_applications'],
                'response_rate' => $applicationAnalytics['response_rate'],
                'interview_rate' => $applicationAnalytics['interview_rate'],
                'offer_rate' => $applicationAnalytics['offer_rate'],
                'average_days_to_response' => $applicationAnalytics['average_days_to_response'],
            ],
            // Job-status pipeline (user_jobs.status) — see JobPipelineService.
            'pipeline' => $jobPipeline,
            'skill_gaps' => $skillGapList,
            'top_roles' => $topRoles,
            'ai_insights' => $insightList,
        ]));
    }

    /**
     * Does the user have any resume at all? Drives the "upload a resume"
     * insight, which is the useful thing to say to someone with no records.
     */
    private function hasResume(User $user): bool
    {
        return DB::table('resumes')
            ->where('user_id', $user->getKey())
            ->exists();
    }

    /**
     * Average AI score across the user's scored resumes, or null when none
     * has been analysed yet (a null score is "unknown", not "zero").
     */
    private function resumeScore(User $user): ?int
    {
        $average = DB::table('resumes')
            ->where('user_id', $user->getKey())
            ->whereNotNull('ai_score')
            ->avg('ai_score');

        return $average === null ? null : (int) round((float) $average);
    }

    /**
     * How many matches the user has run, so we only nudge when it is zero.
     */
    private function matchCount(User $user): int
    {
        return (int) DB::table('job_resume_matches')
            ->join('user_jobs', 'user_jobs.id', '=', 'job_resume_matches.job_id')
            ->where('user_jobs.user_id', $user->getKey())
            ->count();
    }
}
