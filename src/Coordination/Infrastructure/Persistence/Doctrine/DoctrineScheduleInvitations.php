<?php

declare(strict_types=1);

namespace App\Coordination\Infrastructure\Persistence\Doctrine;

use App\Coordination\Domain\ScheduleInvitation;
use App\Coordination\Domain\ScheduleInvitations;
use App\Coordination\Domain\ScheduleInvitationStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DoctrineScheduleInvitations implements ScheduleInvitations
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(ScheduleInvitation $invitation): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO coordination_schedule_invitations (id, inviter_id, token_hash, expires_at, status, accepted_by, accepted_at, revoked_at, created_at)
                 VALUES (:id, :inviter, :hash, :expires, :status, :accepted_by, :accepted_at, :revoked_at, :created)
            ON CONFLICT (id) DO UPDATE SET status = EXCLUDED.status, accepted_by = EXCLUDED.accepted_by, accepted_at = EXCLUDED.accepted_at, revoked_at = EXCLUDED.revoked_at
            SQL, [
            'id' => $invitation->id(), 'inviter' => $invitation->inviterId(), 'hash' => $invitation->tokenHash(),
            'expires' => $invitation->expiresAt()->format(DateTimeImmutable::ATOM), 'status' => $invitation->status()->value,
            'accepted_by' => $invitation->acceptedBy(), 'accepted_at' => $invitation->acceptedAt()?->format(DateTimeImmutable::ATOM),
            'revoked_at' => $invitation->revokedAt()?->format(DateTimeImmutable::ATOM), 'created' => $invitation->createdAt()->format(DateTimeImmutable::ATOM),
        ]);
    }

    public function byTokenHash(string $tokenHash): ?ScheduleInvitation
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM coordination_schedule_invitations WHERE token_hash = :hash', ['hash' => $tokenHash]);

        return false === $row ? null : $this->restore($row);
    }

    /** @param array<string, mixed> $row */
    private function restore(array $row): ScheduleInvitation
    {
        return ScheduleInvitation::restore($this->text($row['id'] ?? null), $this->text($row['inviter_id'] ?? null), $this->text($row['token_hash'] ?? null), new DateTimeImmutable($this->text($row['expires_at'] ?? null)), ScheduleInvitationStatus::from($this->text($row['status'] ?? null)), new DateTimeImmutable($this->text($row['created_at'] ?? null)), null !== ($row['accepted_by'] ?? null) ? $this->text($row['accepted_by']) : null, null !== ($row['accepted_at'] ?? null) ? new DateTimeImmutable($this->text($row['accepted_at'])) : null, null !== ($row['revoked_at'] ?? null) ? new DateTimeImmutable($this->text($row['revoked_at'])) : null);
    }

    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
