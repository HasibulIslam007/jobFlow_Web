<?php

namespace App\Enums;

enum JobStatus: string
{
    case Saved = 'saved';
    case Preparing = 'preparing';
    case Applied = 'applied';
    case Interview = 'interview';
    case Offer = 'offer';
    case Rejected = 'rejected';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
