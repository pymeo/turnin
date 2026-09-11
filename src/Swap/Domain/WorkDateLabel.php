<?php

declare(strict_types=1);

namespace App\Swap\Domain;

/**
 * How a date reads on a card. A shift is found by its day of the week far more
 * often than by its number — "el sábado 19" is how people talk about a rota —
 * so the weekday leads.
 */
final readonly class WorkDateLabel
{
    private const WEEKDAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public static function headline(WorkDate $date): string
    {
        return \sprintf('%s %d %s', self::WEEKDAYS[$date->dayOfWeek() - 1], $date->day, self::MONTHS[$date->month - 1]);
    }

    public static function short(WorkDate $date): string
    {
        return \sprintf('%d %s', $date->day, self::MONTHS[$date->month - 1]);
    }
}
