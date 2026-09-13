<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Application\ExternalCalendar\CalendarMappingDirection;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarConnections;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarMappings;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarProvider;
use App\Scheduling\Application\ExternalCalendar\ExternalRosterEvents;
use App\Scheduling\Application\ExternalCalendar\RosterCalendarExporter;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\RosterDays;
use RuntimeException;

final readonly class ExportRosterCalendarHandler
{
    public function __construct(private RosterWorkspace $workspace, private RosterDays $days, private RosterCalendarExporter $exporter, private ExternalCalendarProvider $provider, private ExternalCalendarConnections $connections, private ExternalCalendarMappings $mappings, private ExternalRosterEvents $externalEvents)
    {
    }

    public function __invoke(ExportRosterCalendar $command): RosterCalendarExported
    {
        $worker = $this->workspace->requireAssignment($command->workerId, $command->assignmentId);
        $connection = $this->connections->activeFor($command->workerId) ?? throw new RuntimeException('Conecta Google Calendar antes de exportar.');
        if (!$connection->canWriteEvents()) {
            throw new RuntimeException('Activa el permiso de exportación de Google Calendar.');
        }
        $created = 0;
        $updated = 0;
        foreach ($this->exporter->events($worker, $this->days->inRange($worker->assignmentId, $command->from, $command->to)) as $event) {
            $externalId = $this->externalEvents->externalEventIdForSegment($connection->id, $command->externalCalendarId, $event->segmentId);
            if (null === $externalId) {
                $externalId = $this->provider->createEvent($command->workerId, $command->externalCalendarId, $event->event);
                $this->externalEvents->recordExport($connection->id, $command->externalCalendarId, $externalId, $worker->assignmentId, $event->rosterDayId, $event->segmentId);
                ++$created;
            } else {
                $this->provider->updateEvent($command->workerId, $command->externalCalendarId, $externalId, $event->event);
                ++$updated;
            }
        }
        $this->mappings->save($connection->id, $command->externalCalendarId, $worker->assignmentId, CalendarMappingDirection::EXPORT);

        return new RosterCalendarExported($created, $updated);
    }
}
