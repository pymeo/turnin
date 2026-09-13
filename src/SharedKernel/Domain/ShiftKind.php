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

    /**
     * Every kind a worker can offer to cover.
     *
     * This has to stay the complete set. A swap request takes its kind from the
     * roster, so any of these can be published; availability is matched to a
     * request by exact kind. Offering fewer kinds here than a roster can hold
     * does not narrow the feature — it makes those requests impossible to match,
     * and they sit at "0 personas disponibles" forever with nothing to show for
     * it. See docs/DECISIONS.md.
     *
     * @return non-empty-list<self>
     */
    public static function offerable(): array
    {
        return self::cases();
    }
}
