<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use DateTimeImmutable;

final readonly class CalendarEntryView
{
    public function __construct(
        public string $id,
        public string $kind,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public bool $allDay,
        public string $displayLabel,
        public string $source,
        public bool $blocksAvailability,
        public ?string $workerAssignmentId = null,
        public ?string $rosterDayId = null,
        public ?string $colorKey = null,
    ) {
    }
}
