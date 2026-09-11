<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface WorkerAssignmentWriter
{
    /** @param non-empty-list<SwapPoolAccess> $accesses */
    public function replacePrimary(WorkerAssignment $assignment, array $accesses): void;
}
