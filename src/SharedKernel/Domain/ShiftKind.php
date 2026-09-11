<?php

declare(strict_types=1);

namespace App\SharedKernel\Domain;

/**
 * The semantic band of a shift, shared by Scheduling and Swap.
 *
 * Hours are deliberately not identity: two centres may start a morning at
 * different times and still mean the same kind of shift.
 */
enum ShiftKind: string
{
    case MORNING = 'morning';
    case EVENING = 'evening';
    case NIGHT = 'night';
    case LONG_DAY = 'long_day';
    case LONG_NIGHT = 'long_night';
    case ON_CALL = 'on_call';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MORNING => 'Mañana',
            self::EVENING => 'Tarde',
            self::NIGHT => 'Noche',
            self::LONG_DAY => 'Turno de 12 h (día)',
            self::LONG_NIGHT => 'Turno de 12 h (noche)',
            self::ON_CALL => 'Guardia',
            self::OTHER => 'Turno',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::MORNING, self::LONG_DAY => 'morning',
            self::EVENING => 'evening',
            self::NIGHT, self::LONG_NIGHT => 'night',
            self::ON_CALL, self::OTHER => 'oncall',
        };
    }

    /** @return non-empty-list<self> The simple choices supported by availability today. */
    public static function basic(): array
    {
        return [self::MORNING, self::EVENING, self::NIGHT];
    }
}
