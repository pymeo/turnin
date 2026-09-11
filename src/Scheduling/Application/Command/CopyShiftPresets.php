<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

final readonly class CopyShiftPresets
{
    public function __construct(public string $workerId, public string $sourceAssignmentId, public string $targetAssignmentId)
    {
    }
}
