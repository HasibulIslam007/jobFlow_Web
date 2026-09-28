<?php

namespace App\Enums;

enum ResumeStatus: string
{
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
