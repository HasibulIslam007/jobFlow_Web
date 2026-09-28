<?php

namespace App\Enums;

enum JobCaptureType: string
{
    case Text = 'text';
    case Pdf = 'pdf';
    case Image = 'image';
    case Url = 'url';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
