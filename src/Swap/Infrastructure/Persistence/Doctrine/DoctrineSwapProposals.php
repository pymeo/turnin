<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Persistence\Doctrine;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Domain\ReturnPreference;
use App\Swap\Domain\SwapProposal;
use App\Swap\Domain\SwapProposalKind;
use App\Swap\Domain\SwapProposalOption;
use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\SwapProposalStatus;
use App\Swap\Domain\WorkDate;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The proposal and the shifts it offers back are written together: a proposal
 * with no options would be a proposal nobody can answer, and the aggregate
 * refuses to exist in that shape.
 */
final readonly class DoctrineSwapProposals implements SwapProposals
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(SwapProposal $proposal): void
    {
        $preference = $proposal->returnPreference();
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO swap_proposals (id, request_id, request_owner_id, proposer_id, proposer_assignment_id, kind, chosen_option_id, return_preference, exchange_balance_id, reserved_minutes, status, approved_by, created_at, updated_at)
            VALUES (:id, :request, :owner, :proposer, :assignment, :kind, :chosen, :preference, :balance, :reserved, :status, :approver, :created, :updated)
            ON CONFLICT (id) DO UPDATE SET status = EXCLUDED.status, chosen_option_id = EXCLUDED.chosen_option_id, approved_by = EXCLUDED.approved_by, updated_at = EXCLUDED.updated_at
            SQL, [
            'id' => $proposal->id(), 'request' => $proposal->requestId(), 'owner' => $proposal->requestOwnerId(),
            'proposer' => $proposal->proposerId(), 'assignment' => $proposal->proposerAssignmentId(), 'kind' => $proposal->kind()->value,
            'chosen' => $proposal->chosenOptionId(),
            'preference' => null === $preference ? null : json_encode(['month' => $preference->month, 'kind' => $preference->shiftKind?->value, 'duration' => $preference->durationMinutes, 'weekdays' => $preference->preferredWeekdays], \JSON_THROW_ON_ERROR),
            'balance' => $proposal->exchangeBalanceId(), 'reserved' => $proposal->reservedMinutes(),
            'approver' => $proposal->approvedBy(),
            'status' => $proposal->status()->value, 'created' => $proposal->createdAt()->format(DateTimeImmutable::ATOM), 'updated' => $proposal->updatedAt()->format(DateTimeImmutable::ATOM),
        ]);

        // Options never change after the proposal is made, so this only ever
        // writes on the first save; the chosen one is a column on the parent.
        foreach ($proposal->options() as $position => $option) {
            $this->connection->executeStatement(
                'INSERT INTO swap_proposal_options (id, proposal_id, assignment_id, roster_day_id, work_date, position) VALUES (:id, :proposal, :assignment, :day, :date, :position) ON CONFLICT (id) DO NOTHING',
                ['id' => $option->id, 'proposal' => $proposal->id(), 'assignment' => $option->assignmentId, 'day' => $option->rosterDayId, 'date' => (string) $option->workDate, 'position' => $position],
            );
        }
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
        return $this->many('SELECT * FROM swap_proposals WHERE request_owner_id = :worker OR proposer_id = :worker ORDER BY created_at DESC', ['worker' => $workerId]);
    }

    public function liveForRequest(string $requestId): array
    {
        return $this->many("SELECT * FROM swap_proposals WHERE request_id = :request AND status IN ('pending', 'pending_approval') ORDER BY created_at", ['request' => $requestId]);
    }

    public function awaitingApprovalInPools(array $poolIds): array
    {
        if ([] === $poolIds) {
            return [];
        }

        return $this->many("SELECT p.* FROM swap_proposals p JOIN swap_requests r ON r.id = p.request_id WHERE p.status = 'pending_approval' AND r.swap_pool_id IN (:pools) ORDER BY p.updated_at", ['pools' => $poolIds], ['pools' => ArrayParameterType::STRING]);
    }

    public function countAwaitingApprovalByPool(array $poolIds): array
    {
        if ([] === $poolIds) {
            return [];
        }
        $counts = [];
        foreach ($this->connection->fetchAllAssociative("SELECT r.swap_pool_id, COUNT(*) AS waiting FROM swap_proposals p JOIN swap_requests r ON r.id = p.request_id WHERE p.status = 'pending_approval' AND r.swap_pool_id IN (:pools) GROUP BY r.swap_pool_id", ['pools' => $poolIds], ['pools' => ArrayParameterType::STRING]) as $row) {
            $pool = $row['swap_pool_id'] ?? null;
            $waiting = $row['waiting'] ?? 0;
            if (\is_string($pool) && is_numeric($waiting)) {
                $counts[$pool] = (int) $waiting;
            }
        }

        return $counts;
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

        return false === $row ? null : $this->hydrate($row, $this->optionsFor([$this->text($row['id'] ?? null)]));
    }

    /**
     * @param array<string, mixed>              $parameters
     * @param array<string, ArrayParameterType> $types
     *
     * @return list<SwapProposal>
     */
    private function many(string $sql, array $parameters, array $types = []): array
    {
        $rows = $this->connection->fetchAllAssociative($sql, $parameters, $types);
        if ([] === $rows) {
            return [];
        }
        // One query for every option of every proposal returned: a board with
        // twenty proposals must not become twenty-one round trips.
        $options = $this->optionsFor(array_map(fn (array $row): string => $this->text($row['id'] ?? null), $rows));

        return array_map(fn (array $row): SwapProposal => $this->hydrate($row, $options), $rows);
    }

    /**
     * @param list<string> $proposalIds
     *
     * @return array<string, list<SwapProposalOption>>
     */
    private function optionsFor(array $proposalIds): array
    {
        $ids = array_values(array_filter(array_unique($proposalIds)));
        if ([] === $ids) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM swap_proposal_options WHERE proposal_id IN (:ids) ORDER BY proposal_id, position',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $options = [];
        foreach ($rows as $row) {
            $options[$this->text($row['proposal_id'] ?? null)][] = new SwapProposalOption(
                $this->text($row['id'] ?? null),
                $this->text($row['assignment_id'] ?? null),
                $this->text($row['roster_day_id'] ?? null),
                WorkDate::fromString($this->text($row['work_date'] ?? null)),
            );
        }

        return $options;
    }

    /**
     * @param array<string, mixed>                    $row
     * @param array<string, list<SwapProposalOption>> $optionsByProposal
     */
    private function hydrate(array $row, array $optionsByProposal): SwapProposal
    {
        $raw = null === ($row['return_preference'] ?? null) ? null : json_decode($this->text($row['return_preference'] ?? null), true, 512, \JSON_THROW_ON_ERROR);
        $preference = \is_array($raw) ? new ReturnPreference(\is_string($raw['month'] ?? null) ? $raw['month'] : null, \is_string($raw['kind'] ?? null) ? ShiftKind::from($raw['kind']) : null, \is_int($raw['duration'] ?? null) ? $raw['duration'] : null, \is_array($raw['weekdays'] ?? null) ? array_values(array_filter($raw['weekdays'], 'is_int')) : []) : null;

        return SwapProposal::restore(
            $this->text($row['id'] ?? null),
            $this->text($row['request_id'] ?? null),
            $this->text($row['request_owner_id'] ?? null),
            $this->text($row['proposer_id'] ?? null),
            $this->text($row['proposer_assignment_id'] ?? null),
            SwapProposalKind::from($this->text($row['kind'] ?? null)),
            $optionsByProposal[$this->text($row['id'] ?? null)] ?? [],
            null === ($row['chosen_option_id'] ?? null) ? null : $this->text($row['chosen_option_id'] ?? null),
            $preference,
            null === ($row['exchange_balance_id'] ?? null) ? null : $this->text($row['exchange_balance_id'] ?? null),
            is_numeric($row['reserved_minutes'] ?? null) ? (int) $row['reserved_minutes'] : 0,
            SwapProposalStatus::from($this->text($row['status'] ?? null)),
            null === ($row['approved_by'] ?? null) ? null : $this->text($row['approved_by'] ?? null),
            new DateTimeImmutable($this->text($row['created_at'] ?? null)),
            new DateTimeImmutable($this->text($row['updated_at'] ?? null)),
        );
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
