<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use DateTimeImmutable;

interface CalendarBlocks
{
    public function save(CalendarBlock $block): void;

    public function byExternalIdentity(string $workerId, string $calendarId, string $eventId): ?CalendarBlock;

    /** @return list<CalendarBlock> */
    public function inRange(string $workerId, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
