<?php

declare(strict_types=1);

namespace App\Swap\Domain;

/**
 * How long a shift lasts, written the way a rota is read.
 *
 * It lives apart from RosteredDay because the same rule writes two different
 * numbers: the length of a shift and the difference between two of them. A
 * guardia of 08:00 a 08:00 is twenty-four hours, never zero, and the label is
 * the only place that says so out loud.
 */
final readonly class ShiftDuration
{
    public static function label(int $minutes): string
    {
        $absolute = abs($minutes);
        $hours = intdiv($absolute, 60);
        $remainder = $absolute % 60;

        return 0 === $remainder ? $hours.' h' : \sprintf('%d h %02d min', $hours, $remainder);
    }
}
