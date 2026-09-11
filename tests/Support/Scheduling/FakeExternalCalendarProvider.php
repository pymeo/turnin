<?php

declare(strict_types=1);

namespace App\Tests\Support\Scheduling;

use App\Scheduling\Application\ExternalCalendar\ExternalCalendar;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarEvent;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarEventDraft;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarPage;
use App\Scheduling\Application\ExternalCalendar\ExternalCalendarProvider;
use DateTimeImmutable;

final class FakeExternalCalendarProvider implements ExternalCalendarProvider
{
    /** @var array<string, list<ExternalCalendarEvent>> */
    public array $events = [];

    /** @var array<string, ExternalCalendarEventDraft> */
    public array $created = [];

    /** @var array<string, ExternalCalendarEventDraft> */
    public array $updated = [];

    public function listCalendars(string $userId): array
    {
        return [new ExternalCalendar('shifts', 'Turnos', true)];
    }

    public function listEvents(string $userId, string $calendarId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $syncToken = null): ExternalCalendarPage
    {
        return new ExternalCalendarPage($this->events[$calendarId] ?? [], 'next-token');
    }

    public function createEvent(string $userId, string $calendarId, ExternalCalendarEventDraft $event): string
    {
        $id = 'external-'.(\count($this->created) + 1);
        $this->created[$id] = $event;

        return $id;
    }

    public function updateEvent(string $userId, string $calendarId, string $eventId, ExternalCalendarEventDraft $event): void
    {
        $this->updated[$eventId] = $event;
    }

    public function revoke(string $userId): void
    {
    }
}
