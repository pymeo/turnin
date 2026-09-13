<?php

declare(strict_types=1);

namespace App\Coordination\Application\Command;

interface CoordinationSwapRequests
{
    public function open(string $workerId, string $workerAssignmentId, string $date): string;
}
