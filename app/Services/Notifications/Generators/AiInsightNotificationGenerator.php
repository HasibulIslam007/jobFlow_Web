<?php

namespace App\Services\Notifications\Generators;

use App\Enums\NotificationType;
use App\Models\User;
use App\Services\Analytics\ApplicationAnalyticsService;
use App\Services\Analytics\SkillGapAnalyzer;
use App\Services\Notifications\NotificationService;

/**
 * AI INSIGHT NOTIFICATIONS — the top finding from the analytics engine.
 *
 * WHY THIS REUSES THE ANALYTICS SERVICES
 * -------------------------------------
 * This generator does no analysis of its own. It calls the same
 * ApplicationAnalyticsService and SkillGapAnalyzer that power GET /analytics,
 * so a notification can never disagree with the dashboard the user clicks
 * through to. The copy is still deterministic and record-derived — no model
 * call — exactly as in the analytics engine.
 *
 * Only ONE insight is ever surfaced: a notification centre stacked with
 * insights stops being read, and the dashboard already lists all of them.
 *
 * DEDUPE KEY
 * ----------
 * `ai_insight:{type}:{subject}`, where the subject is the concrete thing
 * identified (a skill name, or the word "activity"). The key changes only when
 * the underlying finding changes, so a user is nudged when *new* information
 * appears rather than every single day about the same thing.
 */
class AiInsightNotificationGenerator implements NotificationGenerator
{
    /** Only gaps at this importance or above are worth a push. */
    private const MIN_IMPORTANCE = 'medium';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SkillGapAnalyzer $skillGaps,
        private readonly ApplicationAnalyticsService $applications,
    ) {}

    public function name(): string
    {
        return 'ai_insight';
    }

    public function generateFor(User $user): int
    {
        // Skill gap first: it is the most actionable finding a user can act on.
        $gap = $this->topActionableGap($user);

        if ($gap !== null) {
            // Returns the count (0 or 1) and STOPS either way. Falling through
            // to the activity branch when the gap was already delivered would
            // mean a second, different insight appears purely because the
            // sweep ran again — churn that looks like the product has
            // something new to say when it does not.
            return $this->notifications->createIfMissing(
                user: $user,
                type: NotificationType::AiInsight,
                title: 'Skill gap spotted in your target jobs',
                message: "{$gap['skill']} appears in {$gap['frequency']} of your saved jobs but is not "
                    .'listed on your resume. Adding it is the fastest way to lift your match scores.',
                dedupeKey: "ai_insight:skill:{$gap['skill']}",
                data: [
                    'skill' => $gap['skill'],
                    'frequency' => $gap['frequency'],
                    'importance' => $gap['importance'],
                ],
                actionUrl: '/analytics',
            ) !== null ? 1 : 0;
        }

        // No actionable skill gap: flag an inactive search instead, but only
        // once enough applications exist for "low activity" to be a meaningful
        // claim rather than noise.
        $analytics = $this->applications->forUser($user);
        $total = (int) $analytics['total_applications'];

        if ($total === 0 || $total >= 5) {
            return 0;
        }

        $created = $this->notifications->createIfMissing(
            user: $user,
            type: NotificationType::AiInsight,
            title: 'Your application activity is low',
            message: "You have recorded {$total} application"
                .($total === 1 ? '' : 's').' so far. Analytics become far more useful — and your '
                .'follow-up reminders more precise — once there are a handful to compare.',
            dedupeKey: 'ai_insight:activity:low',
            data: ['total_applications' => $total],
            actionUrl: '/applications',
        );

        return $created !== null ? 1 : 0;
    }

    /**
     * The highest-ranked gap at or above the minimum importance, or null.
     *
     * @return array{skill: string, frequency: int, importance: string}|null
     */
    private function topActionableGap(User $user): ?array
    {
        $rank = ['high' => 3, 'medium' => 2, 'low' => 1];

        foreach ($this->skillGaps->forUser($user) as $gap) {
            if (($rank[$gap['importance']] ?? 0) >= ($rank[self::MIN_IMPORTANCE] ?? 2)) {
                return [
                    'skill' => $gap['skill'],
                    'frequency' => $gap['frequency'],
                    'importance' => $gap['importance'],
                ];
            }
        }

        return null;
    }
}
