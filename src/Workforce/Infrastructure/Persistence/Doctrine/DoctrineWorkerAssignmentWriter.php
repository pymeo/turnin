<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Persistence\Doctrine;

use App\Workforce\Domain\SwapPoolKey;
use App\Workforce\Domain\WorkerAssignment;
use App\Workforce\Domain\WorkerAssignmentWriter;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DoctrineWorkerAssignmentWriter implements WorkerAssignmentWriter
{
    public function __construct(private Connection $connection)
    {
    }

    public function replacePrimary(WorkerAssignment $assignment, SwapPoolKey $poolKey, string $poolId, string $membershipId): void
    {
        $now = $assignment->updatedAt();
        $this->connection->executeStatement('UPDATE workforce_worker_assignments SET active = FALSE, updated_at = :now WHERE worker_id = :worker AND active = TRUE', ['now' => $now, 'worker' => $assignment->workerId()], ['now' => 'datetime_immutable']);
        $this->connection->executeStatement('INSERT INTO workforce_worker_assignments (id, worker_id, workplace_id, staff_category_id, specialty_id, organizational_unit_id, functional_area, employer_id, primary_assignment, active, created_at, updated_at) VALUES (:id, :worker, :workplace, :category, :specialty, :unit, :area, :employer, TRUE, TRUE, :created, :updated)', [
            'id' => $assignment->id(), 'worker' => $assignment->workerId(), 'workplace' => (string) $assignment->workplaceId(), 'category' => $assignment->staffCategoryId(), 'specialty' => $assignment->specialtyId(), 'unit' => $assignment->organizationalUnitId(), 'area' => $assignment->functionalArea(), 'employer' => $assignment->employerId(), 'created' => $assignment->createdAt(), 'updated' => $now,
        ], ['created' => 'datetime_immutable', 'updated' => 'datetime_immutable']);
        $this->connection->executeStatement('INSERT INTO workforce_swap_pools (id, workplace_id, staff_category_id, specialty_id, organizational_unit_id, functional_area, employer_id, fingerprint, active, created_at, updated_at) VALUES (:id, :workplace, :category, :specialty, :unit, :area, :employer, :fingerprint, TRUE, :created, :updated) ON CONFLICT (fingerprint) DO UPDATE SET active = TRUE, updated_at = EXCLUDED.updated_at', [
            'id' => $poolId, 'workplace' => (string) $poolKey->workplaceId, 'category' => $poolKey->staffCategoryId, 'specialty' => $poolKey->specialtyId, 'unit' => $poolKey->organizationalUnitId, 'area' => $poolKey->functionalArea, 'employer' => $poolKey->employerId, 'fingerprint' => $poolKey->fingerprint(), 'created' => $now, 'updated' => $now,
        ], ['created' => 'datetime_immutable', 'updated' => 'datetime_immutable']);
        $actualPoolId = $this->connection->fetchOne('SELECT id FROM workforce_swap_pools WHERE fingerprint = :fingerprint', ['fingerprint' => $poolKey->fingerprint()]);
        if (!\is_string($actualPoolId)) {
            throw new RuntimeException('The swap pool could not be resolved after upsert.');
        }
        $this->connection->executeStatement('UPDATE workforce_swap_pool_memberships SET active = FALSE, updated_at = :now WHERE worker_id = :worker AND active = TRUE', ['now' => $now, 'worker' => $assignment->workerId()], ['now' => 'datetime_immutable']);
        $this->connection->executeStatement('INSERT INTO workforce_swap_pool_memberships (id, swap_pool_id, worker_id, assignment_id, active, created_at, updated_at) VALUES (:id, :pool, :worker, :assignment, TRUE, :created, :updated) ON CONFLICT (swap_pool_id, worker_id) DO UPDATE SET assignment_id = EXCLUDED.assignment_id, active = TRUE, updated_at = EXCLUDED.updated_at', ['id' => $membershipId, 'pool' => $actualPoolId, 'worker' => $assignment->workerId(), 'assignment' => $assignment->id(), 'created' => $now, 'updated' => $now], ['created' => 'datetime_immutable', 'updated' => 'datetime_immutable']);
    }
}
