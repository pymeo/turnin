<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Persistence\Doctrine;

use App\Workforce\Domain\Supervision\SupervisorAssignment;
use App\Workforce\Domain\Supervision\SupervisorAssignments;
use App\Workforce\Domain\Supervision\SupervisorAssignmentStatus;
use App\Workforce\Domain\Supervision\SupervisorVerification;
use App\Workforce\Domain\Supervision\SupervisorVerificationDecision;
use App\Workforce\Domain\Supervision\SupervisorVerificationLevel;
use App\Workforce\Domain\Supervision\SupervisorVerificationSource;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * DBAL, like the other aggregates that own a child collection. The unique
 * index on (assignment, verifier) is the last line against double votes; the
 * partial unique index on active (pool, person) against double assignments.
 */
final readonly class DoctrineSupervisorAssignments implements SupervisorAssignments
{
    private const string ACTIVE = "status IN ('pending_verification', 'verified')";

    public function __construct(private Connection $connection)
    {
    }

    public function save(SupervisorAssignment $assignment): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO workforce_supervisor_assignments (id, supervisor_user_id, swap_pool_id, invitation_id, verification_token_hash, status, verification_level, created_at, verified_at, left_at)
            VALUES (:id, :supervisor, :pool, :invitation, :hash, :status, :level, :created, :verified, :left)
            ON CONFLICT (id) DO UPDATE SET status = EXCLUDED.status, verification_level = EXCLUDED.verification_level, verified_at = EXCLUDED.verified_at, left_at = EXCLUDED.left_at
            SQL, [
            'id' => $assignment->id(),
            'supervisor' => $assignment->supervisorUserId(),
            'pool' => $assignment->swapPoolId(),
            'invitation' => $assignment->invitationId(),
            'hash' => $assignment->verificationTokenHash(),
            'status' => $assignment->status()->value,
            'level' => $assignment->verificationLevel()?->value,
            'created' => $assignment->createdAt()->format(DateTimeImmutable::ATOM),
            'verified' => $assignment->verifiedAt()?->format(DateTimeImmutable::ATOM),
            'left' => $assignment->leftAt()?->format(DateTimeImmutable::ATOM),
        ]);
        foreach ($assignment->verifications() as $verification) {
            $this->connection->executeStatement(<<<'SQL'
                INSERT INTO workforce_supervisor_verifications (id, supervisor_assignment_id, verifier_worker_id, decision, source, created_at)
                VALUES (:id, :assignment, :verifier, :decision, :source, :created)
                ON CONFLICT (supervisor_assignment_id, verifier_worker_id) DO UPDATE SET decision = EXCLUDED.decision, source = EXCLUDED.source, created_at = EXCLUDED.created_at
                 WHERE workforce_supervisor_verifications.decision = 'cannot_confirm' AND EXCLUDED.decision = 'confirmed'
                SQL, [
                'id' => $verification->id,
                'assignment' => $assignment->id(),
                'verifier' => $verification->verifierWorkerId,
                'decision' => $verification->decision->value,
                'source' => $verification->source->value,
                'created' => $verification->createdAt->format(DateTimeImmutable::ATOM),
            ]);
        }
    }

    public function byId(string $id): ?SupervisorAssignment
    {
        return $this->one('SELECT * FROM workforce_supervisor_assignments WHERE id = :value', $id);
    }

    public function byIdForUpdate(string $id): ?SupervisorAssignment
    {
        return $this->one('SELECT * FROM workforce_supervisor_assignments WHERE id = :value FOR UPDATE', $id);
    }

    public function byVerificationTokenHash(string $tokenHash): ?SupervisorAssignment
    {
        return $this->one('SELECT * FROM workforce_supervisor_assignments WHERE verification_token_hash = :value', $tokenHash);
    }

    public function byVerificationTokenHashForUpdate(string $tokenHash): ?SupervisorAssignment
    {
        return $this->one('SELECT * FROM workforce_supervisor_assignments WHERE verification_token_hash = :value FOR UPDATE', $tokenHash);
    }

    public function activeFor(string $supervisorUserId, string $swapPoolId): ?SupervisorAssignment
    {
        return $this->many('SELECT * FROM workforce_supervisor_assignments WHERE supervisor_user_id = :supervisor AND swap_pool_id = :pool AND '.self::ACTIVE, ['supervisor' => $supervisorUserId, 'pool' => $swapPoolId])[0] ?? null;
    }

    public function activeForSupervisor(string $supervisorUserId): array
    {
        return $this->many('SELECT * FROM workforce_supervisor_assignments WHERE supervisor_user_id = :supervisor AND '.self::ACTIVE.' ORDER BY created_at', ['supervisor' => $supervisorUserId]);
    }

    public function activeInPool(string $swapPoolId): array
    {
        return $this->many('SELECT * FROM workforce_supervisor_assignments WHERE swap_pool_id = :pool AND '.self::ACTIVE.' ORDER BY created_at', ['pool' => $swapPoolId]);
    }

    private function one(string $sql, string $value): ?SupervisorAssignment
    {
        return $this->many($sql, ['value' => $value])[0] ?? null;
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return list<SupervisorAssignment>
     */
    private function many(string $sql, array $parameters): array
    {
        $rows = $this->connection->fetchAllAssociative($sql, $parameters);
        if ([] === $rows) {
            return [];
        }
        $ids = array_map(fn (array $row): string => $this->text($row['id'] ?? null), $rows);
        $verifications = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM workforce_supervisor_verifications WHERE supervisor_assignment_id IN (:ids) ORDER BY created_at, id', ['ids' => $ids], ['ids' => ArrayParameterType::STRING]) as $row) {
            $verifications[$this->text($row['supervisor_assignment_id'] ?? null)][] = new SupervisorVerification(
                $this->text($row['id'] ?? null),
                $this->text($row['verifier_worker_id'] ?? null),
                SupervisorVerificationDecision::from($this->text($row['decision'] ?? null)),
                SupervisorVerificationSource::from($this->text($row['source'] ?? null)),
                new DateTimeImmutable($this->text($row['created_at'] ?? null)),
            );
        }

        return array_map(fn (array $row): SupervisorAssignment => SupervisorAssignment::restore(
            $this->text($row['id'] ?? null),
            $this->text($row['supervisor_user_id'] ?? null),
            $this->text($row['swap_pool_id'] ?? null),
            null === ($row['invitation_id'] ?? null) ? null : $this->text($row['invitation_id']),
            $this->text($row['verification_token_hash'] ?? null),
            SupervisorAssignmentStatus::from($this->text($row['status'] ?? null)),
            null === ($row['verification_level'] ?? null) ? null : SupervisorVerificationLevel::from($this->text($row['verification_level'])),
            $verifications[$this->text($row['id'] ?? null)] ?? [],
            new DateTimeImmutable($this->text($row['created_at'] ?? null)),
            null === ($row['verified_at'] ?? null) ? null : new DateTimeImmutable($this->text($row['verified_at'])),
            null === ($row['left_at'] ?? null) ? null : new DateTimeImmutable($this->text($row['left_at'])),
        ), $rows);
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
