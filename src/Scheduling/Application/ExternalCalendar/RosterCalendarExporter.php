<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

use App\Scheduling\Domain\AssignedWorker;
use App\Scheduling\Domain\RosterDay;

final readonly class RosterCalendarExporter
{
    /** @param list<RosterDay> $days
     * @return list<ExportableRosterEvent>
     */
    public function events(AssignedWorker $worker, array $days): array
    {
        $result = [];
        foreach ($days as $day) {
            foreach ($day->segments() as $segment) {
                $interval = $segment->intervalOn($day->date(), $worker->timeZone());
                $draft = new ExternalCalendarEventDraft(
                    $segment->labelSnapshot.' · '.$worker->workplaceName,
                    'Turno gestionado por Turnin',
                    $interval->startsAt,
                    $interval->endsAt,
                    ['turninSegmentId' => $segment->id],
                );
                $result[] = new ExportableRosterEvent($day->id(), $segment->id, $draft);
            }
        }

        return $result;
    }
}
