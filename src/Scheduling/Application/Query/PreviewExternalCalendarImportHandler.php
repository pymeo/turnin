<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Application\ExternalCalendar\ExternalCalendarConnections;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarProvider;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarScheduleImporter;
use App\Scheduling\Application\ExternalCalendar\ExternalRosterEvents;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Application\ScheduleDraftPreviewFactory;
use RuntimeException;

final readonly class PreviewExternalCalendarImportHandler
{
    public function __construct(private RosterWorkspace $workspace, private ExternalCalendarProvider $provider, private ExternalCalendarConnections $connections, private ExternalCalendarScheduleImporter $importer, private ExternalRosterEvents $externalEvents, private ScheduleDraftPreviewFactory $previews)
    {
    }

    public function __invoke(PreviewExternalCalendarImport $query): ExternalCalendarImportPreview
    {
        $worker = $this->workspace->requireAssignment($query->workerId, $query->assignmentId);
        $connection = $this->connections->activeFor($query->workerId) ?? throw new RuntimeException('Conecta Google Calendar antes de importar.');
        if (!$connection->canReadEvents()) {
            throw new RuntimeException('Activa el permiso de importación de Google Calendar.');
        }
        $page = $this->provider->listEvents($query->workerId, $query->externalCalendarId, $query->from, $query->to);
        $connectionId = $connection->id;
        $events = array_values(array_filter($page->events, fn ($event): bool => !$this->externalEvents->imported($connectionId, $query->externalCalendarId, $event->id, $worker->assignmentId)));
        $plan = $this->importer->prepare($worker->assignmentId, $events, $this->workspace->presetsFor($worker), $worker->timeZone());
        $recognized = 0;
        $review = 0;
        $ignored = 0;
        foreach ($plan->items as $item) {
            match ($item->status) {
                'recognized' => ++$recognized,
                'review', 'personal' => ++$review,
                default => ++$ignored,
            };
        }

        return new ExternalCalendarImportPreview($this->previews->build($worker, $plan->draft(), $query->policy), $plan->items, $recognized, $review, $ignored);
    }
}
