<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * The band a shift belongs to — not its exact hours. Two hospitals can start
 * the morning at 07:00 and 08:00 and both mean "mañana", which is why matching
 * will reason about the kind and display the snapshot times.
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

    /** The CSS token family this kind paints with. Never a literal colour. */
    public function tone(): string
    {
        return match ($this) {
            self::MORNING, self::LONG_DAY => 'morning',
            self::EVENING => 'evening',
            self::NIGHT, self::LONG_NIGHT => 'night',
            self::ON_CALL, self::OTHER => 'oncall',
        };
    }
}
