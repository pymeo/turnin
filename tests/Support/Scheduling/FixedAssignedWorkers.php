<?php

declare(strict_types=1);

namespace App\Tests\Support\Scheduling;

use App\Scheduling\Domain\AssignedWorker;
use App\Scheduling\Domain\AssignedWorkers;

final readonly class FixedAssignedWorkers implements AssignedWorkers
{
    /** @var list<AssignedWorker> */
    private array $workers;

    /** @param AssignedWorker|list<AssignedWorker>|null $worker */
    public function __construct(AssignedWorker|array|null $worker)
    {
        $this->workers = \is_array($worker) ? $worker : (null === $worker ? [] : [$worker]);
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
        foreach ($this->workers as $worker) {
            if ($worker->workerId === $workerId && $worker->primary) {
                return $worker;
            }
        }

        return null;
    }

    public function activeFor(string $workerId): array
    {
        return array_values(array_filter($this->workers, static fn (AssignedWorker $worker): bool => $worker->workerId === $workerId));
    }

    public function byIdFor(string $workerId, string $assignmentId): ?AssignedWorker
    {
        foreach ($this->activeFor($workerId) as $worker) {
            if ($worker->assignmentId === $assignmentId) {
                return $worker;
            }
        }

        return null;
    }
}
