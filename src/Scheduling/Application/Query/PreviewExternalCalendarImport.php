<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Domain\ConflictPolicy;
use DateTimeImmutable;

final readonly class PreviewExternalCalendarImport
{
    public function __construct(public string $workerId, public string $assignmentId, public string $externalCalendarId, public DateTimeImmutable $from, public DateTimeImmutable $to, public ConflictPolicy $policy = ConflictPolicy::SKIP_EXISTING)
    {
    }
}
