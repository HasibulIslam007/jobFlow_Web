<?php

namespace App\Services\Analytics;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Career health score (Phase 6.8).
 *
 * A weighted blend of four factors, each already normalised to 0–100:
 *
 *   Resume Quality        30%   avg(resumes.ai_score) for this user
 *   Application Activity  25%   volume + recency of applications
 *   Interview Conversion  25%   share of applications reaching interview+
 *   Job Match Quality     20%   avg(job_resume_matches.match_score)
 *
 * DETERMINISM IS THE POINT
 * -----------------------
 * The same records always produce the same score. There is no randomness, no
 * jitter and no "AI" call here: a health score that changes when you refresh
 * is worse than no score at all, because a user cannot act on a number that
 * moves on its own. Every input is a real aggregate over the user's rows.
 *
 * A factor with no data scores 0 rather than being skipped or imputed. That is
 * the honest reading — "no resume uploaded" genuinely is a gap in your job
 * search, and quietly redistributing its weight would let a user with one
 * perfect resume look healthier than a user who did the full pipeline.
 */
class CareerScoreService
{
    /** Factor weights. Must sum to 1.0. */
    private const WEIGHTS = [
        'resume' => 0.30,
        'activity' => 0.25,
        'conversion' => 0.25,
        'matching' => 0.20,
    ];

    /**
     * Applications that count as a fully "active" search. Saturating, not
     * linear: going from 0 to 5 applications is the real signal, 5 to 20 is
     * steady effort, and beyond that more applications stop being the lever.
     */
    private const ACTIVITY_VOLUME_TARGET = 20;

    /** Applications inside the activity window that count as currently active. */
    private const ACTIVITY_RECENCY_TARGET = 5;

    /** Share of the activity component attributed to raw count vs. recency. */
    private const ACTIVITY_VOLUME_SHARE = 0.6;

    private const ACTIVITY_RECENCY_SHARE = 0.4;

    /**
     * Score bands, highest threshold first so the first match wins.
     *
     * @var array<int, string>
     */
    private const LABELS = [
        85 => 'Excellent',
        70 => 'Strong Candidate',
        40 => 'Developing',
        0 => 'Needs Improvement',
    ];

    /**
     * The labels this engine can emit, for tests and documentation.
     *
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return array_values(self::LABELS);
    }

    /**
     * Map a 0–100 score onto its band.
     */
    public function labelFor(int $score): string
    {
        foreach (self::LABELS as $threshold => $label) {
            if ($score >= $threshold) {
                return $label;
            }
        }

        return self::LABELS[0];
    }

    /**
     * Career score, label and per-factor breakdown for a user.
     *
     * @param  array<string, mixed>  $applicationAnalytics  Output of ApplicationAnalyticsService::forUser().
     * @return array{score: int, label: string, factors: array<int, array{key: string, label: string, score: int, weight: int}>}
     */
    public function calculate(User $user, array $applicationAnalytics): array
    {
        $total = (int) ($applicationAnalytics['total_applications'] ?? 0);
        $progressed = (int) ($applicationAnalytics['progressed'] ?? 0);

        $factors = [
            [
                'key' => 'resume',
                'label' => 'Resume',
                'score' => $this->resumeQuality($user),
                'weight' => 30,
            ],
            [
                'key' => 'activity',
                'label' => 'Applications',
                'score' => $this->activityQuality(
                    $total,
                    (int) ($applicationAnalytics['recent_applications'] ?? 0),
                ),
                'weight' => 25,
            ],
            [
                'key' => 'conversion',
                'label' => 'Interviews',
                'score' => $this->conversionQuality($total, $progressed),
                'weight' => 25,
            ],
            [
                'key' => 'matching',
                'label' => 'Matching',
                'score' => $this->matchQuality($user),
                'weight' => 20,
            ],
        ];

        $score = 0;

        foreach ($factors as $factor) {
            $score += $factor['score'] * self::WEIGHTS[$factor['key']];
        }

        $score = (int) round(max(0, min(100, $score)));

        return [
            'score' => $score,
            'label' => $this->labelFor($score),
            'factors' => $factors,
        ];
    }

    /**
     * Average AI resume score. Null when the user has no scored resume.
     *
     * Only the user's own resumes are considered, and an upload that has not
     * been analysed yet (ai_score IS NULL) is ignored rather than counted as
     * 0 — a freshly uploaded resume must not read as a bad one.
     */
    private function resumeQuality(User $user): int
    {
        $average = DB::table('resumes')
            ->where('user_id', $user->getKey())
            ->whereNotNull('ai_score')
            ->avg('ai_score');

        return $average === null ? 0 : $this->clamp((int) round((float) $average));
    }

    /**
     * Volume and recency combined into a single activity number.
     */
    private function activityQuality(int $total, int $recent): int
    {
        if ($total === 0) {
            return 0;
        }

        $volume = min(1.0, $total / self::ACTIVITY_VOLUME_TARGET);
        $recency = min(1.0, $recent / self::ACTIVITY_RECENCY_TARGET);

        return $this->clamp((int) round(
            ($volume * self::ACTIVITY_VOLUME_SHARE + $recency * self::ACTIVITY_RECENCY_SHARE) * 100
        ));
    }

    /**
     * Share of applications that reached interview or offer.
     */
    private function conversionQuality(int $total, int $progressed): int
    {
        return $total > 0 ? $this->clamp((int) round(($progressed / $total) * 100)) : 0;
    }

    /**
     * Average AI match score across this user's jobs.
     *
     * Scoped through `user_jobs` *and* `resumes`, so a match row only counts
     * when both of its parents belong to the user.
     */
    private function matchQuality(User $user): int
    {
        $average = DB::table('job_resume_matches')
            ->join('user_jobs', 'user_jobs.id', '=', 'job_resume_matches.job_id')
            ->join('resumes', 'resumes.id', '=', 'job_resume_matches.resume_id')
            ->where('user_jobs.user_id', $user->getKey())
            ->where('resumes.user_id', $user->getKey())
            ->avg('job_resume_matches.match_score');

        return $average === null ? 0 : $this->clamp((int) round((float) $average));
    }

    private function clamp(int $value): int
    {
        return max(0, min(100, $value));
    }
}
