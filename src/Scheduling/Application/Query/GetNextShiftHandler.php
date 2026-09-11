<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Application\RosterCalendar;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\WorkDate;

/**
 * "¿Qué me toca ahora?" — the one question the lobby has to answer without the
 * worker doing anything.
 *
 * Today counts as the next shift until it is over: someone opening Turnin at
 * 21:00 before a night shift wants to see tonight, not tomorrow. "Over" is
 * decided in the assignment's own time zone.
 */
final readonly class GetNextShiftHandler
{
    private const LOOKAHEAD_IN_DAYS = 45;

    private const WEEKDAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private RosterWorkspace $workspace, private RosterDays $rosterDays, private RosterCalendar $calendar)
    {
    }

    public function __invoke(GetNextShift $query): ?NextShiftView
    {
        $worker = $this->workspace->find($query->workerId);
        if (null === $worker) {
            return null;
        }

        $today = $this->calendar->today($worker);
        $nowInMinutes = (int) $this->calendar->nowLocal($worker)->format('G') * 60 + (int) $this->calendar->nowLocal($worker)->format('i');

        foreach ($this->rosterDays->inRange($worker->assignmentId, $today, $today->plusDays(self::LOOKAHEAD_IN_DAYS)) as $day) {
            if (!$day->isWorking()) {
                continue;
            }
            $segment = $day->firstSegment();
            if (null === $segment) {
                continue;
            }
            if ($day->date()->equals($today) && !$segment->endsNextDay() && $segment->window->end->minuteOfDay() <= $nowInMinutes) {
                continue;
            }

            return new NextShiftView(
                (string) $day->date(),
                $this->when($day->date(), $today),
                $segment->labelSnapshot,
                \sprintf('%s–%s', $segment->window->start, $segment->window->end),
                $segment->kind->tone(),
                $segment->endsNextDay(),
            );
        }

        return null;
    }

    private function when(WorkDate $date, WorkDate $today): string
    {
        return match ($today->daysUntil($date)) {
            0 => 'Hoy',
            1 => 'Mañana',
            default => \sprintf('%s %d de %s', ucfirst(self::WEEKDAYS[$date->dayOfWeek() - 1]), $date->day, self::MONTHS[$date->month - 1]),
        };
    }
}
