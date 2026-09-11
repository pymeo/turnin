<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Persistence\Doctrine;

use App\Workforce\Domain\SwapPoolAccess;
use App\Workforce\Domain\WorkerAssignment;
use App\Workforce\Domain\WorkerAssignments;
use App\Workforce\Domain\WorkerAssignmentWriter;
use App\Workforce\Domain\WorkplaceId;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DoctrineWorkerAssignmentWriter implements WorkerAssignmentWriter, WorkerAssignments
{
    public function __construct(private Connection $connection)
    {
    }

    public function replacePrimary(WorkerAssignment $assignment, array $accesses): void
    {
        if ([] === $accesses) {
            throw new RuntimeException('A worker assignment needs primary pool access.');
        }
        $this->connection->transactional(function () use ($assignment, $accesses): void {
            $now = $assignment->updatedAt();
            $previousId = $this->connection->fetchOne('SELECT id FROM workforce_worker_assignments WHERE worker_id = :worker AND active = TRUE AND primary_assignment = TRUE', ['worker' => $assignment->workerId()]);
            if (\is_string($previousId)) {
                $this->connection->executeStatement('UPDATE workforce_worker_assignments SET active = FALSE, primary_assignment = FALSE, updated_at = :now WHERE id = :id', ['now' => $now, 'id' => $previousId], ['now' => 'datetime_immutable']);
                $this->connection->executeStatement('UPDATE workforce_swap_pool_memberships SET active = FALSE, updated_at = :now WHERE assignment_id = :id AND active = TRUE', ['now' => $now, 'id' => $previousId], ['now' => 'datetime_immutable']);
            }
            $this->insertAssignment($assignment, true);
            foreach ($accesses as $access) {
                $this->persistAccess($assignment, $access);
            }
        });
    }

    public function add(WorkerAssignment $assignment, array $accesses): void
    {
        if ([] === $accesses) {
            throw new RuntimeException('A worker assignment needs primary pool access.');
        }
        $this->connection->transactional(function () use ($assignment, $accesses): void {
            $this->insertAssignment($assignment, null === $this->primaryForWorker($assignment->workerId()));
            foreach ($accesses as $access) {
                $this->persistAccess($assignment, $access);
            }
        });
    }

    public function primaryForWorker(string $workerId): ?WorkerAssignment
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM workforce_worker_assignments WHERE worker_id = :worker AND active = TRUE AND primary_assignment = TRUE LIMIT 1', ['worker' => $workerId]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function activeForWorker(string $workerId): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM workforce_worker_assignments WHERE worker_id = :worker AND active = TRUE ORDER BY primary_assignment DESC, created_at, id', ['worker' => $workerId]);

        return array_map(fn (array $row): WorkerAssignment => $this->hydrate($row), $rows);
    }

    public function byIdForWorker(string $workerId, string $assignmentId): ?WorkerAssignment
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM workforce_worker_assignments WHERE worker_id = :worker AND id = :id', ['worker' => $workerId, 'id' => $assignmentId]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function save(WorkerAssignment $assignment): void
    {
        $this->connection->executeStatement('UPDATE workforce_worker_assignments SET primary_assignment = :primary, active = :active, updated_at = :updated WHERE id = :id AND worker_id = :worker', ['primary' => $assignment->primary(), 'active' => $assignment->active(), 'updated' => $assignment->updatedAt(), 'id' => $assignment->id(), 'worker' => $assignment->workerId()], ['primary' => 'boolean', 'active' => 'boolean', 'updated' => 'datetime_immutable']);
    }

    public function setPrimary(string $workerId, string $assignmentId, DateTimeImmutable $now): void
    {
        $this->connection->transactional(function () use ($workerId, $assignmentId, $now): void {
            $assignment = $this->byIdForWorker($workerId, $assignmentId);
            if (null === $assignment || !$assignment->active()) {
                throw new RuntimeException('Ese lugar de trabajo no está activo.');
            }
            $this->connection->executeStatement('UPDATE workforce_worker_assignments SET primary_assignment = FALSE, updated_at = :now WHERE worker_id = :worker AND active = TRUE AND primary_assignment = TRUE', ['now' => $now, 'worker' => $workerId], ['now' => 'datetime_immutable']);
            $this->connection->executeStatement('UPDATE workforce_worker_assignments SET primary_assignment = TRUE, updated_at = :now WHERE id = :id AND worker_id = :worker AND active = TRUE', ['now' => $now, 'id' => $assignmentId, 'worker' => $workerId], ['now' => 'datetime_immutable']);
        });
    }

    public function deactivate(string $workerId, string $assignmentId, DateTimeImmutable $now): void
    {
        $this->connection->transactional(function () use ($workerId, $assignmentId, $now): void {
            $assignment = $this->byIdForWorker($workerId, $assignmentId) ?? throw new RuntimeException('Ese lugar de trabajo no existe.');
            $this->connection->executeStatement('UPDATE workforce_worker_assignments SET active = FALSE, primary_assignment = FALSE, updated_at = :now WHERE id = :id AND worker_id = :worker', ['now' => $now, 'id' => $assignmentId, 'worker' => $workerId], ['now' => 'datetime_immutable']);
            $this->connection->executeStatement('UPDATE workforce_swap_pool_memberships SET active = FALSE, updated_at = :now WHERE assignment_id = :id AND worker_id = :worker', ['now' => $now, 'id' => $assignmentId, 'worker' => $workerId], ['now' => 'datetime_immutable']);
            if ($assignment->primary()) {
                $next = $this->connection->fetchOne('SELECT id FROM workforce_worker_assignments WHERE worker_id = :worker AND active = TRUE ORDER BY created_at, id LIMIT 1', ['worker' => $workerId]);
                if (\is_string($next)) {
                    $this->connection->executeStatement('UPDATE workforce_worker_assignments SET primary_assignment = TRUE, updated_at = :now WHERE id = :id AND worker_id = :worker AND active = TRUE', ['now' => $now, 'id' => $next, 'worker' => $workerId], ['now' => 'datetime_immutable']);
                }
            }
        });
    }

    private function insertAssignment(WorkerAssignment $assignment, bool $primary): void
    {
        $this->connection->executeStatement('INSERT INTO workforce_worker_assignments (id, worker_id, workplace_id, staff_category_id, specialty_id, organizational_unit_id, functional_area, employer_id, primary_assignment, active, created_at, updated_at) VALUES (:id, :worker, :workplace, :category, :specialty, :unit, :area, :employer, :primary, TRUE, :created, :updated)', ['id' => $assignment->id(), 'worker' => $assignment->workerId(), 'workplace' => (string) $assignment->workplaceId(), 'category' => $assignment->staffCategoryId(), 'specialty' => $assignment->specialtyId(), 'unit' => $assignment->organizationalUnitId(), 'area' => $assignment->functionalArea(), 'employer' => $assignment->employerId(), 'primary' => $primary, 'created' => $assignment->createdAt(), 'updated' => $assignment->updatedAt()], ['primary' => 'boolean', 'created' => 'datetime_immutable', 'updated' => 'datetime_immutable']);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): WorkerAssignment
    {
        $text = static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '';
        $nullable = static fn (mixed $value): ?string => \is_string($value) && '' !== $value ? $value : null;

        return WorkerAssignment::restore($text($row['id'] ?? null), $text($row['worker_id'] ?? null), new WorkplaceId($text($row['workplace_id'] ?? null)), $text($row['staff_category_id'] ?? null), $nullable($row['specialty_id'] ?? null), $nullable($row['organizational_unit_id'] ?? null), $nullable($row['functional_area'] ?? null), $nullable($row['employer_id'] ?? null), (bool) ($row['primary_assignment'] ?? false), (bool) ($row['active'] ?? false), new DateTimeImmutable($text($row['created_at'] ?? null)), new DateTimeImmutable($text($row['updated_at'] ?? null)));
    }

    private function persistAccess(WorkerAssignment $assignment, SwapPoolAccess $access): void
    {
        $key = $access->key;
        $now = $assignment->updatedAt();
        $this->connection->executeStatement('INSERT INTO workforce_swap_pools (id, workplace_id, staff_category_id, specialty_id, organizational_unit_id, functional_area, employer_id, fingerprint, active, created_at, updated_at) VALUES (:id, :workplace, :category, :specialty, :unit, :area, :employer, :fingerprint, TRUE, :created, :updated) ON CONFLICT (fingerprint) DO UPDATE SET active = TRUE, updated_at = EXCLUDED.updated_at', ['id' => $access->poolId, 'workplace' => (string) $key->workplaceId, 'category' => $key->staffCategoryId, 'specialty' => $key->specialtyId, 'unit' => $key->organizationalUnitId, 'area' => $key->functionalArea, 'employer' => $key->employerId, 'fingerprint' => $key->fingerprint(), 'created' => $now, 'updated' => $now], ['created' => 'datetime_immutable', 'updated' => 'datetime_immutable']);
        $actualPoolId = $this->connection->fetchOne('SELECT id FROM workforce_swap_pools WHERE fingerprint = :fingerprint', ['fingerprint' => $key->fingerprint()]);
        if (!\is_string($actualPoolId)) {
            throw new RuntimeException('The swap pool could not be resolved after upsert.');
        }
        $this->connection->executeStatement('INSERT INTO workforce_swap_pool_memberships (id, swap_pool_id, worker_id, assignment_id, source, is_primary, active, created_at, updated_at) VALUES (:id, :pool, :worker, :assignment, :source, :primary, TRUE, :created, :updated) ON CONFLICT (swap_pool_id, worker_id, assignment_id) DO UPDATE SET source = EXCLUDED.source, is_primary = EXCLUDED.is_primary, active = TRUE, updated_at = EXCLUDED.updated_at', ['id' => $access->membershipId, 'pool' => $actualPoolId, 'worker' => $assignment->workerId(), 'assignment' => $assignment->id(), 'source' => $access->source->value, 'primary' => $access->primary, 'created' => $now, 'updated' => $now], ['primary' => 'boolean', 'created' => 'datetime_immutable', 'updated' => 'datetime_immutable']);
    }
}
