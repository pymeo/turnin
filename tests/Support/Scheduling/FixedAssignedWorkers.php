<?php

declare(strict_types=1);

namespace App\Tests\Support\Scheduling;

use App\Scheduling\Domain\AssignedWorker;
use App\Scheduling\Domain\AssignedWorkers;

final readonly class FixedAssignedWorkers implements AssignedWorkers
{
    public function __construct(private ?AssignedWorker $worker)
    {
    }

    public static function inMadrid(string $workerId = 'worker-1', string $assignmentId = 'assignment-1'): self
    {
        return new self(new AssignedWorker($workerId, $assignmentId, 'Europe/Madrid', 'Hospital de prueba'));
    }

    public static function inTheCanaries(string $workerId = 'worker-1', string $assignmentId = 'assignment-1'): self
    {
        return new self(new AssignedWorker($workerId, $assignmentId, 'Atlantic/Canary', 'Hospital Universitario de Canarias'));
    }

    public static function withoutAssignment(): self
    {
        return new self(null);
    }

    public function primaryFor(string $workerId): ?AssignedWorker
    {
        return null !== $this->worker && $this->worker->workerId === $workerId ? $this->worker : null;
    }
}
