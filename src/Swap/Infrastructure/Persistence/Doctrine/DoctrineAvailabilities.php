<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Persistence\Doctrine;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Domain\Availabilities;
use App\Swap\Domain\Availability;
use App\Swap\Domain\WorkDate;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DoctrineAvailabilities implements Availabilities
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(Availability $availability): void
    {
        // The conflict target is the unique index on worker + pool + day, so a
        // double tap updates one row instead of racing to insert a second.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO swap_availabilities (id, worker_id, worker_assignment_id, swap_pool_id, work_date, shift_kind, active, created_at, updated_at)
                     VALUES (:id, :worker, :assignment, :pool, :date, :kind, :active, :created, :updated)
                ON CONFLICT (worker_id, swap_pool_id, work_date, shift_kind)
                  DO UPDATE SET active = EXCLUDED.active,
                                worker_assignment_id = EXCLUDED.worker_assignment_id,
                                updated_at = EXCLUDED.updated_at
                SQL,
            [
                'id' => $availability->id(),
                'worker' => $availability->workerId(),
                'assignment' => $availability->workerAssignmentId(),
                'pool' => $availability->swapPoolId(),
                'date' => (string) $availability->workDate(),
                'kind' => $availability->shiftKind()->value,
                'active' => $availability->isActive(),
                'created' => $availability->createdAt()->format(DateTimeImmutable::ATOM),
                'updated' => $availability->updatedAt()->format(DateTimeImmutable::ATOM),
            ],
            ['active' => 'boolean'],
        );
    }

    public function byId(string $id): ?Availability
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM swap_availabilities WHERE id = :id', ['id' => $id]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function forSlot(string $workerId, string $swapPoolId, WorkDate $date, ShiftKind $shiftKind): ?Availability
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM swap_availabilities WHERE worker_id = :worker AND swap_pool_id = :pool AND work_date = :date AND shift_kind = :kind',
            ['worker' => $workerId, 'pool' => $swapPoolId, 'date' => (string) $date, 'kind' => $shiftKind->value],
        );

        return false === $row ? null : $this->hydrate($row);
    }

    public function activeByWorkerOnDate(string $workerId, WorkDate $date): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM swap_availabilities WHERE worker_id = :worker AND active = TRUE AND work_date = :date ORDER BY swap_pool_id, shift_kind',
            ['worker' => $workerId, 'date' => (string) $date],
        );

        return array_map(fn (array $row): Availability => $this->hydrate($row), $rows);
    }

    public function activeByWorker(string $workerId, WorkDate $from): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM swap_availabilities WHERE worker_id = :worker AND active = TRUE AND work_date > :from ORDER BY work_date',
            ['worker' => $workerId, 'from' => (string) $from],
        );

        return array_map(fn (array $row): Availability => $this->hydrate($row), $rows);
    }

    public function activeInPoolOnDate(string $swapPoolId, WorkDate $date, ShiftKind $shiftKind, string $excludingWorkerId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT ON (worker_id) * FROM swap_availabilities WHERE swap_pool_id = :pool AND work_date = :date AND active = TRUE AND worker_id <> :worker ORDER BY worker_id, (shift_kind = :kind) DESC, created_at',
            ['pool' => $swapPoolId, 'date' => (string) $date, 'kind' => $shiftKind->value, 'worker' => $excludingWorkerId],
        );

        return array_map(fn (array $row): Availability => $this->hydrate($row), $rows);
    }

    public function activeDatesFor(string $workerId, WorkDate $from, WorkDate $to): array
    {
        /** @var list<string> $dates */
        $dates = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT work_date FROM swap_availabilities WHERE worker_id = :worker AND active = TRUE AND work_date BETWEEN :from AND :to',
            ['worker' => $workerId, 'from' => (string) $from, 'to' => (string) $to],
        );

        return $dates;
    }

    public function activePoolsOnDate(string $workerId, WorkDate $date): array
    {
        /** @var list<string> $pools */
        $pools = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT swap_pool_id FROM swap_availabilities WHERE worker_id = :worker AND work_date = :date AND active = TRUE',
            ['worker' => $workerId, 'date' => (string) $date],
        );

        return $pools;
    }

    public function activePoolsOnDates(string $workerId, array $dates): array
    {
        if ([] === $dates) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT work_date, shift_kind, swap_pool_id FROM swap_availabilities WHERE worker_id = :worker AND active = TRUE AND work_date IN (:dates)',
            ['worker' => $workerId, 'dates' => array_map(static fn (WorkDate $date): string => (string) $date, $dates)],
            ['dates' => ArrayParameterType::STRING],
        );

        $byDate = [];
        foreach ($rows as $row) {
            $key = $this->text($row['work_date'] ?? null).'|'.$this->text($row['shift_kind'] ?? null);
            $byDate[$key][] = $this->text($row['swap_pool_id'] ?? null);
        }

        return $byDate;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Availability
    {
        return Availability::restore(
            $this->text($row['id'] ?? null),
            $this->text($row['worker_id'] ?? null),
            $this->text($row['worker_assignment_id'] ?? null),
            $this->text($row['swap_pool_id'] ?? null),
            WorkDate::fromString($this->text($row['work_date'] ?? null)),
            ShiftKind::from($this->text($row['shift_kind'] ?? null)),
            (bool) ($row['active'] ?? false),
            new DateTimeImmutable($this->text($row['created_at'] ?? null)),
            new DateTimeImmutable($this->text($row['updated_at'] ?? null)),
        );
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
