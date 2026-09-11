<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

interface ExternalCalendarMappings
{
    public function save(string $connectionId, string $externalCalendarId, string $workerAssignmentId, CalendarMappingDirection $direction, ?string $syncToken = null): void;

    public function syncToken(string $connectionId, string $externalCalendarId, string $workerAssignmentId): ?string;

    public function clearSyncToken(string $connectionId, string $externalCalendarId, string $workerAssignmentId): void;
}
