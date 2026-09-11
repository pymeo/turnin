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
        foreach ($this->activeFor($workerId) as $worker) {
            if ($worker->primary) {
                return $worker;
            }
        }

        return null;
    }

    public function activeFor(string $workerId): array
    {
        return $this->find($workerId);
    }

    public function byIdFor(string $workerId, string $assignmentId): ?AssignedWorker
    {
        foreach ($this->find($workerId, $assignmentId) as $worker) {
            return $worker;
        }

        return null;
    }

    /** @return list<AssignedWorker> */
    private function find(string $workerId, ?string $assignmentId = null): array
    {
        $sql = <<<'SQL'
            SELECT a.id, a.primary_assignment, w.name, w.autonomous_community,
                   COALESCE(u.name, '') AS destination_name
              FROM workforce_worker_assignments a
              JOIN workforce_workplaces w ON w.id = a.workplace_id
              LEFT JOIN workforce_organizational_units u ON u.id = a.organizational_unit_id
             WHERE a.worker_id = :worker AND a.active = TRUE
            SQL;
        $parameters = ['worker' => $workerId];
        if (null !== $assignmentId) {
            $sql .= ' AND a.id = :assignment';
            $parameters['assignment'] = $assignmentId;
        }
        $sql .= ' ORDER BY a.primary_assignment DESC, a.created_at, a.id';
        $rows = $this->connection->fetchAllAssociative($sql, $parameters);

        return array_map(fn (array $row): AssignedWorker => new AssignedWorker(
            $workerId,
            $this->text($row['id'] ?? null),
            WorkplaceTimeZone::forAutonomousCommunity($this->nullable($row['autonomous_community'] ?? null)),
            $this->text($row['name'] ?? null),
            (bool) ($row['primary_assignment'] ?? false),
            $this->text($row['destination_name'] ?? null),
        ), $rows);
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
