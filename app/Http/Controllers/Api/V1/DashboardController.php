<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardResource;
use App\Http\Responses\ApiResponse;
use App\Services\AI\Analysis\MissingFieldDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * Aggregate product dashboard for the authenticated user.
     *
     * Single round-trip powering the Next.js dashboard: pipeline stats,
     * upcoming deadlines with days_remaining, recent captures and AI insights.
     */
    public function __invoke(Request $request, MissingFieldDetector $missingFields): JsonResponse
    {
        $user = $request->user();
        $today = Carbon::today();

        $jobsQuery = $user->jobs();

        $totalJobs = (clone $jobsQuery)->count();

        $counts = (clone $jobsQuery)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $countFor = fn (JobStatus $status): int => (int) ($counts[$status->value] ?? 0);

        $stats = [
            'total_jobs' => $totalJobs,
            'saved' => $countFor(JobStatus::Saved),
            'applied' => $countFor(JobStatus::Applied),
            'interview' => $countFor(JobStatus::Interview),
            'offer' => $countFor(JobStatus::Offer),
            'rejected' => $countFor(JobStatus::Rejected),
        ];

        $upcomingDeadlines = (clone $jobsQuery)
            ->whereNotNull('deadline')
            ->orderBy('deadline')
            ->limit(5)
            ->get(['id', 'title', 'company', 'deadline', 'status'])
            ->map(fn ($job): array => [
                'id' => $job->id,
                'title' => $job->title,
                'company' => $job->company,
                'deadline' => $job->deadline->toDateString(),
                'status' => $job->status instanceof \BackedEnum ? $job->status->value : $job->status,
                'days_remaining' => (int) $today->diffInDays($job->deadline, false),
            ])
            ->all();

        $recentCaptures = $user->jobCaptures()
            ->latest('id')
            ->limit(5)
            ->get();

        $scoreAggregates = (clone $jobsQuery)
            ->selectRaw('AVG(ai_confidence_score) as avg_confidence, AVG(job_quality_score) as avg_quality')
            ->first();

        $incompleteJobs = (clone $jobsQuery)
            ->where(function ($query): void {
                $query->whereNull('location')
                    ->orWhere('location', '')
                    ->orWhereNull('salary')
                    ->orWhere('salary', '')
                    ->orWhereNull('deadline');
            })
            ->orWhere(function ($query) use ($user): void {
                // Jobs whose description carries no extracted signal beyond the raw paste.
                $query->where('user_id', $user->getKey())
                    ->whereNull('description');
            })
            ->count();

        $payload = new DashboardResource([
            'stats' => $stats,
            'upcoming_deadlines' => $upcomingDeadlines,
            'recent_captures' => $recentCaptures,
            'ai_insights' => [
                'average_confidence' => $scoreAggregates->avg_confidence !== null
                    ? round((float) $scoreAggregates->avg_confidence, 3)
                    : null,
                'average_quality_score' => $scoreAggregates->avg_quality !== null
                    ? (int) round((float) $scoreAggregates->avg_quality)
                    : null,
                'missing_information_count' => $incompleteJobs,
            ],
        ]);

        return ApiResponse::success($payload);
    }
}
