<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

final readonly class ApplyManualShift
{
    public function __construct(public string $workerId, public string $assignmentId, public string $date, public string $label, public string $abbreviation, public string $start, public string $end, public string $kind, public string $colorKey)
    {
    }
}
