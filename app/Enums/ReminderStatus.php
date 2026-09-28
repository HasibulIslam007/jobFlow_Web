<?php

namespace App\Enums;

enum ReminderStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Dismissed = 'dismissed';
    case Failed = 'failed';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
