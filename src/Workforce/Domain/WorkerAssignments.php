<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

interface WorkerAssignments
{
    public function primaryForWorker(string $workerId): ?WorkerAssignment;

    public function save(WorkerAssignment $assignment): void;
}
