<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Domain\ConflictPolicy;
use DateTimeImmutable;

final readonly class ImportExternalCalendar
{
    /** @param list<string> $externalEventIds */
    public function __construct(public string $workerId, public string $assignmentId, public string $externalCalendarId, public DateTimeImmutable $from, public DateTimeImmutable $to, public array $externalEventIds, public ConflictPolicy $policy = ConflictPolicy::SKIP_EXISTING)
    {
    }
}
