<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

final readonly class DeactivateWorkerAssignment
{
    public function __construct(public string $workerId, public string $assignmentId)
    {
    }
}
