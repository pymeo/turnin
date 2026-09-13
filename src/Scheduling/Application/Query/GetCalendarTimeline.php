<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use DateTimeImmutable;

final readonly class GetCalendarTimeline
{
    public function __construct(public string $workerId, public DateTimeImmutable $from, public DateTimeImmutable $to)
    {
    }
}
