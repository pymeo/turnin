<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

use DateTimeImmutable;

interface WorkerAssignmentWriter
{
    /** @param non-empty-list<SwapPoolAccess> $accesses */
    public function replacePrimary(WorkerAssignment $assignment, array $accesses): void;

    /** @param non-empty-list<SwapPoolAccess> $accesses */
    public function add(WorkerAssignment $assignment, array $accesses): void;

    public function setPrimary(string $workerId, string $assignmentId, DateTimeImmutable $now): void;

    public function deactivate(string $workerId, string $assignmentId, DateTimeImmutable $now): void;
}
