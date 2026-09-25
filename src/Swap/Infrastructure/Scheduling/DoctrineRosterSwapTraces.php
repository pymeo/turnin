<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Scheduling;

use App\Scheduling\Application\Query\RosterAgreementStatus;
use App\Scheduling\Application\Query\RosterSwapRole;
use App\Scheduling\Application\Query\RosterSwapTrace;
use App\Scheduling\Application\Query\RosterSwapTraces;
use App\Scheduling\Application\Query\RosterSwapTraceSegment;
use Doctrine\DBAL\Connection;

final readonly class DoctrineRosterSwapTraces implements RosterSwapTraces
{
    public function __construct(private Connection $connection)
    {
    }

    public function forWorkerInRange(string $workerId, string $from, string $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT a.proposal_id, a.requested_date, a.requested_segments, a.return_date, a.return_segments,
                       p.request_owner_id, p.proposer_id, p.status,
                       owner_profile.given_name AS owner_name, proposer_profile.given_name AS proposer_name
                  FROM swap_agreement_snapshots a
                  JOIN swap_proposals p ON p.id = a.proposal_id
                  LEFT JOIN identity_personal_profiles owner_profile ON owner_profile.user_id = p.request_owner_id
                  LEFT JOIN identity_personal_profiles proposer_profile ON proposer_profile.user_id = p.proposer_id
                 WHERE (p.request_owner_id = :worker OR p.proposer_id = :worker)
                   AND (a.requested_date BETWEEN :from AND :to OR a.return_date BETWEEN :from AND :to)
                 ORDER BY a.reached_at
                SQL,
            ['worker' => $workerId, 'from' => $from, 'to' => $to],
        );
        $traces = [];
        foreach ($rows as $row) {
            $owner = $this->text($row['request_owner_id'] ?? null);
            $isOwner = $workerId === $owner;
            $colleague = $isOwner ? $this->text($row['proposer_name'] ?? 'Un compañero') : $this->text($row['owner_name'] ?? 'Un compañero');
            $status = $this->status($this->text($row['status'] ?? null));
            $requestedDate = $this->text($row['requested_date'] ?? null);
            if ($requestedDate >= $from && $requestedDate <= $to) {
                $traces[] = new RosterSwapTrace($requestedDate, $isOwner ? RosterSwapRole::GIVEN_AWAY : RosterSwapRole::TAKEN_FROM_COLLEAGUE, $colleague, $this->text($row['proposal_id'] ?? null), $status, $this->segments($this->text($row['requested_segments'] ?? null)));
            }
            $returnDate = null === ($row['return_date'] ?? null) ? null : $this->text($row['return_date'] ?? null);
            if (null !== $returnDate && $returnDate >= $from && $returnDate <= $to) {
                $traces[] = new RosterSwapTrace($returnDate, $isOwner ? RosterSwapRole::TAKEN_FROM_COLLEAGUE : RosterSwapRole::GIVEN_AWAY, $colleague, $this->text($row['proposal_id'] ?? null), $status, $this->segments($this->text($row['return_segments'] ?? null)));
            }
        }

        return $traces;
    }

    private function status(string $status): RosterAgreementStatus
    {
        return match ($status) {
            'pending_approval' => RosterAgreementStatus::PENDING,
            'executed', 'accepted' => RosterAgreementStatus::CONFIRMED,
            default => RosterAgreementStatus::CANCELLED,
        };
    }

    /** @return list<RosterSwapTraceSegment> */
    private function segments(string $json): array
    {
        $rows = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($rows)) {
            return [];
        }

        $segments = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $segments[] = new RosterSwapTraceSegment($this->text($row['start'] ?? null), $this->text($row['end'] ?? null), $this->number($row['durationMinutes'] ?? null), $this->text($row['label'] ?? null), $this->text($row['abbreviation'] ?? null), $this->text($row['color'] ?? null));
        }

        return $segments;
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
