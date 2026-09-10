<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface WorkerAssignmentWriter
{
    public function replacePrimary(WorkerAssignment $assignment, SwapPoolKey $poolKey, string $poolId, string $membershipId): void;
}
