<?php

namespace App\Enums;

enum NotificationType: string
{
    case DeadlineReminder = 'deadline_reminder';
    case ApplicationUpdate = 'application_update';
    case System = 'system';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
