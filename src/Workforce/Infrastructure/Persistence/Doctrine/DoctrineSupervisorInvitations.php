<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Persistence\Doctrine;

use App\Workforce\Domain\Supervision\SupervisorInvitation;
use App\Workforce\Domain\Supervision\SupervisorInvitations;
use App\Workforce\Domain\Supervision\SupervisorInvitationStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineSupervisorInvitations implements SupervisorInvitations
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(SupervisorInvitation $invitation): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO workforce_supervisor_invitations (id, swap_pool_id, invited_by_worker_id, token_hash, status, expires_at, responded_by_user_id, created_at, responded_at)
            VALUES (:id, :pool, :inviter, :hash, :status, :expires, :responder, :created, :responded)
            ON CONFLICT (id) DO UPDATE SET status = EXCLUDED.status, responded_by_user_id = EXCLUDED.responded_by_user_id, responded_at = EXCLUDED.responded_at
            SQL, [
            'id' => $invitation->id(),
            'pool' => $invitation->swapPoolId(),
            'inviter' => $invitation->invitedByWorkerId(),
            'hash' => $invitation->tokenHash(),
            'status' => $invitation->status()->value,
            'expires' => $invitation->expiresAt()->format(DateTimeImmutable::ATOM),
            'responder' => $invitation->respondedByUserId(),
            'created' => $invitation->createdAt()->format(DateTimeImmutable::ATOM),
            'responded' => $invitation->respondedAt()?->format(DateTimeImmutable::ATOM),
        ]);
    }

    public function byTokenHash(string $tokenHash): ?SupervisorInvitation
    {
        return $this->one('SELECT * FROM workforce_supervisor_invitations WHERE token_hash = :hash', $tokenHash);
    }

    public function byTokenHashForUpdate(string $tokenHash): ?SupervisorInvitation
    {
        return $this->one('SELECT * FROM workforce_supervisor_invitations WHERE token_hash = :hash FOR UPDATE', $tokenHash);
    }

    public function pendingFrom(string $invitedByWorkerId, string $swapPoolId): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM workforce_supervisor_invitations WHERE invited_by_worker_id = :inviter AND swap_pool_id = :pool AND status = 'pending' FOR UPDATE", ['inviter' => $invitedByWorkerId, 'pool' => $swapPoolId]);

        return array_map($this->hydrate(...), $rows);
    }

    private function one(string $sql, string $tokenHash): ?SupervisorInvitation
    {
        $row = $this->connection->fetchAssociative($sql, ['hash' => $tokenHash]);

        return false === $row ? null : $this->hydrate($row);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SupervisorInvitation
    {
        return SupervisorInvitation::restore(
            $this->text($row['id'] ?? null),
            $this->text($row['swap_pool_id'] ?? null),
            $this->text($row['invited_by_worker_id'] ?? null),
            $this->text($row['token_hash'] ?? null),
            SupervisorInvitationStatus::from($this->text($row['status'] ?? null)),
            new DateTimeImmutable($this->text($row['expires_at'] ?? null)),
            null === ($row['responded_by_user_id'] ?? null) ? null : $this->text($row['responded_by_user_id']),
            new DateTimeImmutable($this->text($row['created_at'] ?? null)),
            null === ($row['responded_at'] ?? null) ? null : new DateTimeImmutable($this->text($row['responded_at'])),
        );
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
