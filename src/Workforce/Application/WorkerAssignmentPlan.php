<?php

declare(strict_types=1);

namespace App\Workforce\Application;

use App\Workforce\Domain\SwapPoolAccess;
use App\Workforce\Domain\WorkerAssignment;

final readonly class WorkerAssignmentPlan
{
    /** @param non-empty-list<SwapPoolAccess> $accesses */
    public function __construct(public WorkerAssignment $assignment, public array $accesses)
    {
    }
}
