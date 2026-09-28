<?php

namespace App\Enums;

enum NotificationType: string
{
    /**
     * Phase 5.7 types. Retained verbatim — renaming an existing enum value is
     * a breaking API change for any client that already stores it.
     */
    case DeadlineReminder = 'deadline_reminder';
    case ApplicationUpdate = 'application_update';
    case System = 'system';

    /**
     * Phase 6.9 intelligence types, produced by the generators in
     * App\Services\Notifications\Generators from real user activity.
     */
    case FollowUp = 'follow_up';
    case Interview = 'interview';
    case Resume = 'resume';
    case AiInsight = 'ai_insight';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Types produced by the Phase 6.9 generator sweep, as opposed to the
     * Phase 5.7 event types written by explicit application/reminder flows.
     *
     * @return array<int, string>
     */
    public static function generatedValues(): array
    {
        return [
            self::FollowUp->value,
            self::Interview->value,
            self::Resume->value,
            self::AiInsight->value,
        ];
    }
}
