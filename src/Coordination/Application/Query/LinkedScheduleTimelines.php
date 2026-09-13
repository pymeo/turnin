<?php

declare(strict_types=1);

namespace App\Coordination\Application\Query;

use App\Coordination\Domain\BusyInterval;
use DateTimeImmutable;

interface LinkedScheduleTimelines
{
    /** @return list<BusyInterval> */
    public function forUser(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
