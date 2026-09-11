<?php

declare(strict_types=1);

namespace App\Tests\Support\Scheduling;

use App\Scheduling\Application\ExternalCalendar\CalendarMappingDirection;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarConnection;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarConnections;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarMappings;
use App\Scheduling\Application\ExternalCalendar\ExternalRosterEvents;
use DateTimeImmutable;

final class InMemoryExternalCalendars implements ExternalCalendarConnections, ExternalCalendarMappings, ExternalRosterEvents
{
    /** @var array<string, string> */
    private array $segmentEvents = [];

    /** @var array<string, true> */
    private array $imports = [];

    public function connect(string $userId, string $accountSubject, string $accessToken, ?string $refreshToken, ?DateTimeImmutable $expiresAt, array $scopes): void
    {
    }

    public function activeFor(string $userId): ?ExternalCalendarConnection
    {
        return new ExternalCalendarConnection('google:'.$userId, $userId, 'subject', 'access', 'refresh', null, [], null);
    }

    public function refreshAccessToken(string $userId, string $accessToken, ?DateTimeImmutable $expiresAt): void
    {
    }

    public function disconnect(string $userId): void
    {
    }

    public function save(string $connectionId, string $externalCalendarId, string $workerAssignmentId, CalendarMappingDirection $direction, ?string $syncToken = null): void
    {
    }

    public function syncToken(string $connectionId, string $externalCalendarId, string $workerAssignmentId): ?string
    {
        return null;
    }

    public function clearSyncToken(string $connectionId, string $externalCalendarId, string $workerAssignmentId): void
    {
    }

    public function imported(string $connectionId, string $calendarId, string $eventId, string $assignmentId): bool
    {
        return isset($this->imports[$connectionId.'|'.$calendarId.'|'.$eventId.'|'.$assignmentId]);
    }

    public function externalEventIdForSegment(string $connectionId, string $calendarId, string $segmentId): ?string
    {
        return $this->segmentEvents[$connectionId.'|'.$calendarId.'|'.$segmentId] ?? null;
    }

    public function recordImport(string $connectionId, string $calendarId, string $eventId, string $assignmentId, string $rosterDayId, ?string $segmentId, DateTimeImmutable $externalUpdatedAt): void
    {
        $this->imports[$connectionId.'|'.$calendarId.'|'.$eventId.'|'.$assignmentId] = true;
    }

    public function recordExport(string $connectionId, string $calendarId, string $eventId, string $assignmentId, string $rosterDayId, ?string $segmentId): void
    {
        if (null !== $segmentId) {
            $this->segmentEvents[$connectionId.'|'.$calendarId.'|'.$segmentId] = $eventId;
        }
    }
}
