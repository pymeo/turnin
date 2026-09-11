<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Domain\WorkDate;

final readonly class ExportRosterIcs
{
    public function __construct(public string $workerId, public string $assignmentId, public WorkDate $from, public WorkDate $to)
    {
    }
}
