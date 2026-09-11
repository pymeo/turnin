<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

use App\Scheduling\Domain\AssignedWorker;
use App\Scheduling\Domain\RosterDay;
use DateTimeZone;

final readonly class IcsRosterExporter
{
    /** @param list<RosterDay> $days */
    public function export(AssignedWorker $worker, array $days): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Turnin//Roster//ES', 'CALSCALE:GREGORIAN'];
        foreach ($days as $day) {
            foreach ($day->segments() as $segment) {
                $interval = $segment->intervalOn($day->date(), $worker->timeZone());
                array_push($lines,
                    'BEGIN:VEVENT',
                    'UID:'.hash('sha256', $segment->id).'@turnin.app',
                    'DTSTART:'.$interval->startsAt->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
                    'DTEND:'.$interval->endsAt->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
                    'SUMMARY:'.$this->escape($segment->labelSnapshot.' · '.$worker->workplaceName),
                    'DESCRIPTION:Turno gestionado por Turnin',
                    'END:VEVENT',
                );
            }
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r", "\n"], ['\\\\', '\\;', '\\,', '', '\\n'], $value);
    }
}
