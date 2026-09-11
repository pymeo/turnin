<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Application\ExternalCalendar\CalendarMappingDirection;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarConnections;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarMappings;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarProvider;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarScheduleImporter;
use App\Scheduling\Application\ExternalCalendar\ExternalRosterEvents;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\RosterSource;
use RuntimeException;

final readonly class ImportExternalCalendarHandler
{
    public function __construct(
        private RosterWorkspace $workspace,
        private ExternalCalendarProvider $provider,
        private ExternalCalendarScheduleImporter $importer,
        private ExternalCalendarConnections $connections,
        private ExternalCalendarMappings $mappings,
        private ExternalRosterEvents $externalEvents,
        private ApplyScheduleDraftHandler $apply,
        private RosterDays $rosterDays,
    ) {
    }

    public function __invoke(ImportExternalCalendar $command): ScheduleDraftApplied
    {
        $worker = $this->workspace->requireAssignment($command->workerId, $command->assignmentId);
        $connection = $this->connections->activeFor($command->workerId) ?? throw new RuntimeException('Conecta Google Calendar antes de importar.');
        $page = $this->provider->listEvents($command->workerId, $command->externalCalendarId, $command->from, $command->to);
        $eventsById = [];
        foreach ($page->events as $event) {
            if ('' !== $event->id && !$this->externalEvents->imported($connection->id, $command->externalCalendarId, $event->id, $worker->assignmentId)) {
                $eventsById[$event->id] = $event;
            }
        }
        $selected = [];
        foreach (array_unique($command->externalEventIds) as $eventId) {
            if (isset($eventsById[$eventId])) {
                $selected[] = $eventsById[$eventId];
            }
        }
        $plan = $this->importer->prepare($worker->assignmentId, $selected, $this->workspace->presetsFor($worker), $worker->timeZone());
        $draft = $plan->draft($command->externalEventIds);
        $result = ($this->apply)(new ApplyScheduleDraft($command->workerId, [], RosterSource::GOOGLE_CALENDAR, $command->policy, $worker->assignmentId, $draft));

        foreach ($plan->items as $item) {
            if ('recognized' !== $item->status) {
                continue;
            }
            $day = $this->rosterDays->onDate($worker->assignmentId, $item->entry->date);
            $external = $eventsById[$item->externalEventId] ?? null;
            if (null !== $day && RosterSource::GOOGLE_CALENDAR === $day->source() && null !== $external) {
                $this->externalEvents->recordImport($connection->id, $command->externalCalendarId, $external->id, $worker->assignmentId, $day->id(), $day->firstSegment()?->id, $external->updatedAt);
            }
        }
        $this->mappings->save($connection->id, $command->externalCalendarId, $worker->assignmentId, CalendarMappingDirection::IMPORT, $page->nextSyncToken);

        return $result;
    }
}
