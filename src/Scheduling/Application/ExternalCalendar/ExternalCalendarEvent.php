<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

use DateTimeImmutable;

final readonly class ExternalCalendarEvent
{
    public function __construct(
        public string $id,
        public string $title,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public bool $allDay,
        public bool $cancelled,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
