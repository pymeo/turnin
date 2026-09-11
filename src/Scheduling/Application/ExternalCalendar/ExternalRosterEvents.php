<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

use DateTimeImmutable;

interface ExternalRosterEvents
{
    public function imported(string $connectionId, string $calendarId, string $eventId, string $assignmentId): bool;

    public function externalEventIdForSegment(string $connectionId, string $calendarId, string $segmentId): ?string;

    public function recordImport(string $connectionId, string $calendarId, string $eventId, string $assignmentId, string $rosterDayId, ?string $segmentId, DateTimeImmutable $externalUpdatedAt): void;

    public function recordExport(string $connectionId, string $calendarId, string $eventId, string $assignmentId, string $rosterDayId, ?string $segmentId): void;
}
