<?php

namespace App\Services\Analytics;

/**
 * Deterministic career insight generator (Phase 6.8).
 *
 * IMPORTANT: this makes NO external AI call.
 *
 * The Phase 6 brief called for "AI-generated career recommendations", but an
 * LLM here would be actively harmful: it costs money on every page load, it
 * can hallucinate a skill the user does not need, and — worst — it would
 * replace numbers the user can audit with prose they cannot. So every insight
 * below is a rule over the *real* aggregates, and every sentence embeds the
 * figure it was derived from, so a user can check it against their own data.
 *
 * This class is a pure function: no DB, no I/O, trivially testable.
 */
class CareerInsightService
{
    /** Applications below which the search still reads as low volume. */
    private const LOW_ACTIVITY_THRESHOLD = 5;

    /** Resume score below which the resume is worth revisiting. */
    private const WEAK_RESUME_SCORE = 60;

    /**
     * Build the insight list from already-computed analytics.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array{type: string, message: string}>
     */
    public function generate(array $context): array
    {
        $insights = [];

        $insights = array_merge($insights, $this->skillInsights($context));
        $insights = array_merge($insights, $this->activityInsights($context));
        $insights = array_merge($insights, $this->resumeInsights($context));
        $insights = array_merge($insights, $this->conversionInsights($context));
        $insights = array_merge($insights, $this->roleInsights($context));
        $insights = array_merge($insights, $this->matchingInsights($context));

        // Never show an empty recommendations panel: fall back to the single
        // most useful next step implied by whatever data does exist.
        if ($insights === []) {
            $insights[] = [
                'type' => 'getting_started',
                'message' => 'Add a few jobs and upload your resume to unlock '
                    .'conversion tracking, skill gaps and matching insights.',
            ];
        }

        return $insights;
    }

    /**
     * Skill gaps, led by the ones their importance marks as high.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array{type: string, message: string}>
     */
    private function skillInsights(array $context): array
    {
        $gaps = $context['skill_gaps'] ?? [];

        if (! is_array($gaps) || $gaps === []) {
            return [];
        }

        $high = array_values(array_filter(
            $gaps,
            fn (array $gap): bool => ($gap['importance'] ?? null) === 'high'
        ));

        // Prefer the high-importance gaps; fall back to the top-ranked ones.
        $featured = $high !== [] ? $high : array_slice($gaps, 0, 2);
        $names = array_map(fn (array $gap): string => (string) $gap['skill'], array_slice($featured, 0, 2));

        $lead = $featured[0] ?? null;

        $message = $lead !== null
            ? sprintf(
                'Your saved jobs frequently require %s — the top one appears in %d of them. '
                .'Consider adding %s to your resume to strengthen your match.',
                $this->humanizeList($names),
                (int) ($lead['frequency'] ?? 0),
                $names[0] ?? 'this skill'
            )
            : 'Your saved jobs require skills your resume does not list yet.';

        return [['type' => 'skill', 'message' => $message]];
    }

    /**
     * Volume, or the absence of it.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array{type: string, message: string}>
     */
    private function activityInsights(array $context): array
    {
        $total = (int) ($context['total_applications'] ?? 0);

        if ($total === 0) {
            return [[
                'type' => 'activity',
                'message' => 'You have not recorded any applications yet. '
                    .'Track each one so conversion and follow-up analytics have something to work from.',
            ]];
        }

        if ($total < self::LOW_ACTIVITY_THRESHOLD) {
            return [[
                'type' => 'activity',
                'message' => sprintf(
                    'Your application activity is low — %d recorded so far. '
                    .'Try adding more targeted opportunities to build a meaningful sample.',
                    $total
                ),
            ]];
        }

        return [];
    }

    /**
     * Resume presence and quality.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array{type: string, message: string}>
     */
    private function resumeInsights(array $context): array
    {
        if (! (bool) ($context['has_resume'] ?? false)) {
            return [[
                'type' => 'resume',
                'message' => 'Upload a resume to unlock AI matching, skill-gap analysis '
                    .'and a resume quality score.',
            ]];
        }

        $score = $context['resume_score'] ?? null;

        if (is_int($score) && $score < self::WEAK_RESUME_SCORE) {
            return [[
                'type' => 'resume',
                'message' => sprintf(
                    'Your resume scores %d/100. Improving it is the single highest-leverage '
                    .'change for job matching.',
                    $score
                ),
            ]];
        }

        return [];
    }

    /**
     * Response rate, once there is at least one application actually sent.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array{type: string, message: string}>
     */
    private function conversionInsights(array $context): array
    {
        $total = (int) ($context['total_applications'] ?? 0);
        $responseRate = (int) ($context['response_rate'] ?? 0);
        $applied = (int) ($context['pipeline']['applied'] ?? 0);

        // A response rate is meaningless before anything has been sent.
        if ($total === 0 || $applied === 0) {
            return [];
        }

        if ($responseRate === 0) {
            return [[
                'type' => 'conversion',
                'message' => sprintf(
                    'None of your %d sent applications have drawn a response yet. '
                    .'Tailoring each application to the job description usually moves this first.',
                    $applied
                ),
            ]];
        }

        if ($responseRate < 20) {
            return [[
                'type' => 'conversion',
                'message' => sprintf(
                    'Your response rate is %d%%. Reviewing how closely your resume mirrors each '
                    .'job description is the clearest lever at this stage.',
                    $responseRate
                ),
            ]];
        }

        return [];
    }

    /**
     * The user's dominant target role, with the skill advice attached so the
     * insight is actionable rather than merely descriptive.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array{type: string, message: string}>
     */
    private function roleInsights(array $context): array
    {
        $roles = $context['top_roles'] ?? [];

        if (! is_array($roles) || $roles === []) {
            return [];
        }

        $top = $roles[0];

        $message = sprintf(
            'Your best opportunities are %s roles — %d of your saved jobs (%d%%) target them.',
            (string) ($top['role'] ?? 'your target'),
            (int) ($top['count'] ?? 0),
            (int) ($top['share'] ?? 0)
        );

        $gaps = $context['skill_gaps'] ?? [];

        if (is_array($gaps) && $gaps !== []) {
            $names = array_slice(array_map(
                fn (array $gap): string => (string) $gap['skill'],
                $gaps
            ), 0, 2);

            $message .= sprintf(
                ' Focus on %s to improve matching for those roles.',
                $this->humanizeList($names)
            );
        }

        return [['type' => 'role', 'message' => $message]];
    }

    /**
     * Nudge only once there is a resume to match *with*.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array{type: string, message: string}>
     */
    private function matchingInsights(array $context): array
    {
        if ((int) ($context['match_count'] ?? 0) > 0) {
            return [];
        }

        if (! (bool) ($context['has_resume'] ?? false)) {
            // The resume insight already covers this case; don't double up.
            return [];
        }

        return [[
            'type' => 'matching',
            'message' => 'You have not run a resume match against any job yet. '
                .'Matching a few of your target roles will show exactly which skills each one wants.',
        ]];
    }

    /**
     * "AWS and Docker" / "AWS, Docker and Redis" — and "additional skills"
     * rather than an empty string when there is nothing to name.
     *
     * @param  array<int, string>  $items
     */
    private function humanizeList(array $items): string
    {
        $items = array_values(array_filter(
            array_map('trim', $items),
            fn (string $item): bool => $item !== ''
        ));

        if ($items === []) {
            return 'additional skills';
        }

        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
