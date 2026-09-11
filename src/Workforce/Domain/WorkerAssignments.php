<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface WorkerAssignments
{
    public function primaryForWorker(string $workerId): ?WorkerAssignment;

    /** @return list<WorkerAssignment> */
    public function activeForWorker(string $workerId): array;

    public function byIdForWorker(string $workerId, string $assignmentId): ?WorkerAssignment;

    public function save(WorkerAssignment $assignment): void;
}
