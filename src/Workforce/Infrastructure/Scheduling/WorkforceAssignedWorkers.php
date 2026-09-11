<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Scheduling;

use App\Scheduling\Domain\AssignedWorker;
use App\Scheduling\Domain\AssignedWorkers;
use App\Workforce\Domain\WorkplaceTimeZone;
use Doctrine\DBAL\Connection;

/**
 * Workforce answering a question Scheduling asked. The port is declared by the
 * consumer (see docs/CONTEXT_MAP.md) and implemented here, where the assignment
 * and the centre actually live — Scheduling never reads another context's
 * tables through its own repositories.
 *
 * The assignment is resolved from the signed-in worker, never from the request.
 */
final readonly class WorkforceAssignedWorkers implements AssignedWorkers
{
    public function __construct(private Connection $connection)
    {
    }

    public function primaryFor(string $workerId): ?AssignedWorker
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT a.id, w.name, w.autonomous_community
                  FROM workforce_worker_assignments a
                  JOIN workforce_workplaces w ON w.id = a.workplace_id
                 WHERE a.worker_id = :worker AND a.active = TRUE AND a.primary_assignment = TRUE
                 ORDER BY a.created_at DESC
                 LIMIT 1
                SQL,
            ['worker' => $workerId],
        );

        if (false === $row) {
            return null;
        }

        return new AssignedWorker(
            $workerId,
            $this->text($row['id'] ?? null),
            WorkplaceTimeZone::forAutonomousCommunity($this->nullable($row['autonomous_community'] ?? null)),
            $this->text($row['name'] ?? null),
        );
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private function nullable(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
