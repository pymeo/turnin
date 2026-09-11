<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Persistence\Doctrine;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\SwapRequestStatus;
use App\Swap\Domain\WorkDate;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DoctrineSwapRequests implements SwapRequests
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(SwapRequest $request): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO swap_requests (id, worker_id, worker_assignment_id, swap_pool_id, roster_day_id, work_date, shift_kind, status, created_at, updated_at)
                     VALUES (:id, :worker, :assignment, :pool, :day, :date, :kind, :status, :created, :updated)
                ON CONFLICT (id) DO UPDATE SET status = EXCLUDED.status, updated_at = EXCLUDED.updated_at
                SQL,
            [
                'id' => $request->id(),
                'worker' => $request->workerId(),
                'assignment' => $request->workerAssignmentId(),
                'pool' => $request->swapPoolId(),
                'day' => $request->rosterDayId(),
                'date' => (string) $request->workDate(),
                'kind' => $request->shiftKind()->value,
                'status' => $request->status()->value,
                'created' => $request->createdAt()->format(DateTimeImmutable::ATOM),
                'updated' => $request->updatedAt()->format(DateTimeImmutable::ATOM),
            ],
        );
    }

    public function byId(string $id): ?SwapRequest
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM swap_requests WHERE id = :id', ['id' => $id]);

        return false === $row ? null : $this->hydrate($row);
    }

    public function openFor(string $workerAssignmentId, WorkDate $date): ?SwapRequest
    {
        $row = $this->connection->fetchAssociative(
            "SELECT * FROM swap_requests WHERE worker_assignment_id = :assignment AND work_date = :date AND status = 'open'",
            ['assignment' => $workerAssignmentId, 'date' => (string) $date],
        );

        return false === $row ? null : $this->hydrate($row);
    }

    public function openInPools(array $swapPoolIds, WorkDate $from, string $excludingWorkerId): array
    {
        if ([] === $swapPoolIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT * FROM swap_requests
                 WHERE swap_pool_id IN (:pools)
                   AND status = 'open'
                   AND work_date > :from
                   AND worker_id <> :worker
                 ORDER BY work_date, created_at
                SQL,
            ['pools' => $swapPoolIds, 'from' => (string) $from, 'worker' => $excludingWorkerId],
            ['pools' => ArrayParameterType::STRING],
        );

        return array_map(fn (array $row): SwapRequest => $this->hydrate($row), $rows);
    }

    public function openByWorker(string $workerId, WorkDate $from): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM swap_requests WHERE worker_id = :worker AND status = 'open' AND work_date > :from ORDER BY work_date",
            ['worker' => $workerId, 'from' => (string) $from],
        );

        return array_map(fn (array $row): SwapRequest => $this->hydrate($row), $rows);
    }

    public function openDatesFor(string $workerAssignmentId, WorkDate $from, WorkDate $to): array
    {
        /** @var list<string> $dates */
        $dates = $this->connection->fetchFirstColumn(
            "SELECT work_date FROM swap_requests WHERE worker_assignment_id = :assignment AND status = 'open' AND work_date BETWEEN :from AND :to",
            ['assignment' => $workerAssignmentId, 'from' => (string) $from, 'to' => (string) $to],
        );

        return $dates;
    }

    public function candidateCounts(array $requestIds): array
    {
        if ([] === $requestIds) {
            return [];
        }

        // One join instead of a query per card: availability is matched to a
        // request by its pool and its day, which is exactly the pair the
        // partial index on swap_availabilities covers.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT r.id, COUNT(a.id) AS candidates
                  FROM swap_requests r
                  LEFT JOIN swap_availabilities a
                         ON a.swap_pool_id = r.swap_pool_id
                        AND a.work_date = r.work_date
                        AND a.shift_kind = r.shift_kind
                        AND a.active = TRUE
                        AND a.worker_id <> r.worker_id
                 WHERE r.id IN (:ids)
                 GROUP BY r.id
                SQL,
            ['ids' => $requestIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[$this->text($row['id'] ?? null)] = is_numeric($row['candidates'] ?? null) ? (int) $row['candidates'] : 0;
        }

        return $counts;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SwapRequest
    {
        return SwapRequest::restore(
            $this->text($row['id'] ?? null),
            $this->text($row['worker_id'] ?? null),
            $this->text($row['worker_assignment_id'] ?? null),
            $this->text($row['swap_pool_id'] ?? null),
            $this->text($row['roster_day_id'] ?? null),
            WorkDate::fromString($this->text($row['work_date'] ?? null)),
            ShiftKind::from($this->text($row['shift_kind'] ?? null)),
            SwapRequestStatus::from($this->text($row['status'] ?? null)),
            new DateTimeImmutable($this->text($row['created_at'] ?? null)),
            new DateTimeImmutable($this->text($row['updated_at'] ?? null)),
        );
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
