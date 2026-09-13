<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Persistence\Doctrine;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Domain\ReturnPreference;
use App\Swap\Domain\SwapProposal;
use App\Swap\Domain\SwapProposalKind;
use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\SwapProposalStatus;
use App\Swap\Domain\WorkDate;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineSwapProposals implements SwapProposals
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(SwapProposal $proposal): void
    {
        $preference = $proposal->returnPreference();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO swap_proposals (id, request_id, request_owner_id, proposer_id, proposer_assignment_id, kind, offered_roster_day_id, offered_work_date, return_preference, exchange_balance_id, reserved_minutes, status, approved_by, created_at, updated_at)
            VALUES (:id, :request, :owner, :proposer, :assignment, :kind, :offered_day, :offered_date, :preference, :balance, :reserved, :status, :approver, :created, :updated)
            ON CONFLICT (id) DO UPDATE SET status = EXCLUDED.status, approved_by = EXCLUDED.approved_by, updated_at = EXCLUDED.updated_at
            SQL, [
            'id' => $proposal->id(), 'request' => $proposal->requestId(), 'owner' => $proposal->requestOwnerId(),
            'proposer' => $proposal->proposerId(), 'assignment' => $proposal->proposerAssignmentId(), 'kind' => $proposal->kind()->value,
            'offered_day' => $proposal->offeredRosterDayId(), 'offered_date' => $proposal->offeredWorkDate()?->__toString(),
            'preference' => null === $preference ? null : json_encode(['month' => $preference->month, 'kind' => $preference->shiftKind?->value, 'duration' => $preference->durationMinutes, 'weekdays' => $preference->preferredWeekdays], \JSON_THROW_ON_ERROR),
            'balance' => $proposal->exchangeBalanceId(), 'reserved' => $proposal->reservedMinutes(),
            'approver' => $proposal->approvedBy(),
            'status' => $proposal->status()->value, 'created' => $proposal->createdAt()->format(DateTimeImmutable::ATOM), 'updated' => $proposal->updatedAt()->format(DateTimeImmutable::ATOM),
        ]);
    }

    public function byId(string $id): ?SwapProposal
    {
        return $this->one('SELECT * FROM swap_proposals WHERE id = :id', ['id' => $id]);
    }

    public function byIdForUpdate(string $id): ?SwapProposal
    {
        return $this->one('SELECT * FROM swap_proposals WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    public function involving(string $workerId): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM swap_proposals WHERE request_owner_id = :worker OR proposer_id = :worker ORDER BY created_at DESC', ['worker' => $workerId]);

        return array_map(fn (array $row): SwapProposal => $this->hydrate($row), $rows);
    }

    public function awaitingApprovalInPools(array $poolIds): array
    {
        if ([] === $poolIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative("SELECT p.* FROM swap_proposals p JOIN swap_requests r ON r.id = p.request_id WHERE p.status = 'pending_approval' AND r.swap_pool_id IN (:pools) ORDER BY p.updated_at", ['pools' => $poolIds], ['pools' => \Doctrine\DBAL\ArrayParameterType::STRING]);

        return array_map(fn (array $row): SwapProposal => $this->hydrate($row), $rows);
    }

    public function activeRedemption(string $balanceId, string $requestId, string $proposerId): ?SwapProposal
    {
        return $this->one("SELECT * FROM swap_proposals WHERE exchange_balance_id = :balance AND request_id = :request AND proposer_id = :proposer AND status IN ('pending', 'pending_approval')", ['balance' => $balanceId, 'request' => $requestId, 'proposer' => $proposerId]);
    }

    public function pendingApprovalForRequest(string $requestId): ?SwapProposal
    {
        return $this->one("SELECT * FROM swap_proposals WHERE request_id = :request AND status = 'pending_approval'", ['request' => $requestId]);
    }

    /** @param array<string, mixed> $parameters */
    private function one(string $sql, array $parameters): ?SwapProposal
    {
        $row = $this->connection->fetchAssociative($sql, $parameters);

        return false === $row ? null : $this->hydrate($row);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SwapProposal
    {
        $text = static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '';
        $offeredDate = $row['offered_work_date'] ?? null;
        $raw = null === ($row['return_preference'] ?? null) ? null : json_decode($text($row['return_preference']), true, 512, \JSON_THROW_ON_ERROR);
        $preference = \is_array($raw) ? new ReturnPreference(\is_string($raw['month'] ?? null) ? $raw['month'] : null, \is_string($raw['kind'] ?? null) ? ShiftKind::from($raw['kind']) : null, \is_int($raw['duration'] ?? null) ? $raw['duration'] : null, \is_array($raw['weekdays'] ?? null) ? array_values(array_filter($raw['weekdays'], 'is_int')) : []) : null;

        return SwapProposal::restore($text($row['id']), $text($row['request_id']), $text($row['request_owner_id']), $text($row['proposer_id']), $text($row['proposer_assignment_id']), SwapProposalKind::from($text($row['kind'])), null === ($row['offered_roster_day_id'] ?? null) ? null : $text($row['offered_roster_day_id']), null === $offeredDate ? null : WorkDate::fromString($text($offeredDate)), $preference, null === ($row['exchange_balance_id'] ?? null) ? null : $text($row['exchange_balance_id']), is_numeric($row['reserved_minutes'] ?? null) ? (int) $row['reserved_minutes'] : 0, SwapProposalStatus::from($text($row['status'])), null === ($row['approved_by'] ?? null) ? null : $text($row['approved_by']), new DateTimeImmutable($text($row['created_at'])), new DateTimeImmutable($text($row['updated_at'])));
    }
}
