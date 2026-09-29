<?php

namespace App\Enums;

/**
 * How a user's AI requests are billed and routed.
 *
 * The enum is a closed set on purpose: an unknown mode is a programming or
 * tampering error, so it is rejected at validation rather than silently
 * treated as a default.
 */
enum AiMode: string
{
    /** Platform key first; fall back to the user's key on quota/rate limits. */
    case Automatic = 'automatic';

    /** Always the platform's configured key. Personal keys are never consulted. */
    case JobFlow = 'jobflow';

    /** Always the user's own key. Never falls back to the platform key. */
    case Personal = 'personal';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Coerce anything to a valid mode, defaulting to automatic.
     *
     * Defensive on purpose: the column has a default and a CHECK-free schema,
     * so a hand-edited row must not be able to take the resolver down.
     */
    public static function parse(mixed $value): self
    {
        return is_string($value)
            ? (self::tryFrom($value) ?? self::Automatic)
            : self::Automatic;
    }
}
