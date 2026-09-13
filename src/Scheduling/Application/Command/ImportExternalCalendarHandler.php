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
use App\Scheduling\Domain\CalendarBlock;
use App\Scheduling\Domain\CalendarBlockIdGenerator;
use App\Scheduling\Domain\CalendarBlocks;
use App\Scheduling\Domain\CalendarBlockSource;
use App\Scheduling\Domain\CalendarBlockType;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\RosterSource;
use Psr\Clock\ClockInterface;
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
        private CalendarBlocks $blocks,
        private CalendarBlockIdGenerator $blockIds,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ImportExternalCalendar $command): ExternalCalendarImported
    {
        $worker = $this->workspace->requireAssignment($command->workerId, $command->assignmentId);
        $connection = $this->connections->activeFor($command->workerId) ?? throw new RuntimeException('Conecta Google Calendar antes de importar.');
        if (!$connection->canReadEvents()) {
            throw new RuntimeException('Activa el permiso de importación de Google Calendar.');
        }
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
        $workDays = 0;
        if (!$draft->isEmpty()) {
            $workDays = ($this->apply)(new ApplyScheduleDraft($command->workerId, [], RosterSource::GOOGLE_CALENDAR, $command->policy, $worker->assignmentId, $draft))->writtenDays;
        }

        $createdBlocks = 0;
        $updatedBlocks = 0;
        foreach ($plan->items as $item) {
            if (!\in_array($item->externalEventId, $command->externalEventIds, true)) {
                continue;
            }
            if ('personal' === $item->status) {
                $existing = $this->blocks->byExternalIdentity($command->workerId, $command->externalCalendarId, $item->externalEventId);
                if (null === $existing) {
                    $this->blocks->save(CalendarBlock::create($this->blockIds->next(), $command->workerId, $item->title, $item->blockType ?? CalendarBlockType::PERSONAL, $item->event->startsAt, $item->event->endsAt, $item->event->allDay, true, CalendarBlockSource::GOOGLE_CALENDAR, $this->clock->now(), $command->externalCalendarId, $item->externalEventId, $item->event->updatedAt));
                    ++$createdBlocks;
                } else {
                    $before = $existing->updatedAt();
                    $existing->synchronize($item->title, $item->event->startsAt, $item->event->endsAt, $item->event->allDay, $item->event->updatedAt, $this->clock->now());
                    $this->blocks->save($existing);
                    $updatedBlocks += $existing->updatedAt() > $before ? 1 : 0;
                }
                continue;
            }
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

        return new ExternalCalendarImported($workDays, $createdBlocks, $updatedBlocks);
    }
}
