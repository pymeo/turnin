<?php

declare(strict_types=1);

namespace App\Coordination\Application\Command;

use DateTimeImmutable;

final readonly class RequestCoordinationDays
{
    /** @param list<string> $assignmentAndDates */
    public function __construct(public string $workerId, public DateTimeImmutable $from, public DateTimeImmutable $to, public array $assignmentAndDates)
    {
    }
}
