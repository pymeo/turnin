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

    private const WEEKDAYS_SHORT = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];

    public static function headline(WorkDate $date): string
    {
        return \sprintf('%s %d %s', self::WEEKDAYS[$date->dayOfWeek() - 1], $date->day, self::MONTHS[$date->month - 1]);
    }

    public static function short(WorkDate $date): string
    {
        return \sprintf('%d %s', $date->day, self::MONTHS[$date->month - 1]);
    }

    /**
     * "vie 18". Short enough for a calendar cell or a badge, and still says the
     * weekday, which is how a rest block is recognised at a glance.
     */
    public static function compact(WorkDate $date): string
    {
        return \sprintf('%s %d', self::weekday($date), $date->day);
    }

    public static function weekday(WorkDate $date): string
    {
        return self::WEEKDAYS_SHORT[$date->dayOfWeek() - 1];
    }
}
