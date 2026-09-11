<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/** A stable presentation token. It never participates in shift compatibility. */
enum ShiftColor: string
{
    case AMBER = 'amber';
    case ORANGE = 'orange';
    case TEAL = 'teal';
    case BLUE = 'blue';
    case INDIGO = 'indigo';
    case VIOLET = 'violet';
    case ROSE = 'rose';
    case SLATE = 'slate';
    case EMERALD = 'emerald';
    case CYAN = 'cyan';

    public static function suggestedFor(ShiftKind $kind): self
    {
        return match ($kind) {
            ShiftKind::MORNING => self::AMBER,
            ShiftKind::EVENING => self::ORANGE,
            ShiftKind::NIGHT, ShiftKind::LONG_NIGHT => self::BLUE,
            ShiftKind::LONG_DAY => self::EMERALD,
            ShiftKind::ON_CALL => self::VIOLET,
            ShiftKind::OTHER => self::SLATE,
        };
    }
}
