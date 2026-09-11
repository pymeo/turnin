<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * Declared here and implemented by Workforce, which owns the data. Scheduling
 * never reaches into another context's classes; it states what it needs and
 * the supplier provides it (see docs/CONTEXT_MAP.md).
 */
interface AssignedWorkers
{
    public function primaryFor(string $workerId): ?AssignedWorker;
}
