<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Domain\WorkDate;

final readonly class ExportRosterCalendar
{
    public function __construct(public string $workerId, public string $assignmentId, public string $externalCalendarId, public WorkDate $from, public WorkDate $to)
    {
    }
}
