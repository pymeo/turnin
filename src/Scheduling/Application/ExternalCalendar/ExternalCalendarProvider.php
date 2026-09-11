<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

use DateTimeImmutable;

interface ExternalCalendarProvider
{
    /** @return list<ExternalCalendar> */
    public function listCalendars(string $userId): array;

    public function listEvents(string $userId, string $calendarId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $syncToken = null): ExternalCalendarPage;

    public function createEvent(string $userId, string $calendarId, ExternalCalendarEventDraft $event): string;

    public function updateEvent(string $userId, string $calendarId, string $eventId, ExternalCalendarEventDraft $event): void;

    public function revoke(string $userId): void;
}
