<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use DateTimeImmutable;

final readonly class CreateCalendarBlock
{
    public function __construct(public string $workerId, public string $title, public string $type, public DateTimeImmutable $startsAt, public DateTimeImmutable $endsAt, public bool $allDay, public bool $blocksAvailability)
    {
    }
}
