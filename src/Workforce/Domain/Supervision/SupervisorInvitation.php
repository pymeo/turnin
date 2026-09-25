<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A colleague saying "this is who validates our changes — come in".
 *
 * Having the link lets somebody *ask* to be the supervisor of that pool; it
 * never makes them one. Once accepted, the process continues through the
 * SupervisorAssignment and this invitation's expiry stops mattering.
 */
final class SupervisorInvitation
{
    private function __construct(
        private readonly string $id,
        private readonly string $swapPoolId,
        private readonly string $invitedByWorkerId,
        private readonly string $tokenHash,
        private SupervisorInvitationStatus $status,
        private readonly DateTimeImmutable $expiresAt,
        private ?string $respondedByUserId,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $respondedAt,
    ) {
        if ('' === trim($id) || '' === trim($swapPoolId) || '' === trim($invitedByWorkerId) || 64 !== \strlen($tokenHash)) {
            throw new InvalidArgumentException('A supervisor invitation needs a pool, an inviter and a token.');
        }
        if ($expiresAt <= $createdAt) {
            throw new InvalidArgumentException('A supervisor invitation must expire in the future.');
        }
    }

    public static function create(string $id, string $swapPoolId, string $invitedByWorkerId, string $tokenHash, DateTimeImmutable $expiresAt, DateTimeImmutable $now): self
    {
        return new self($id, $swapPoolId, $invitedByWorkerId, $tokenHash, SupervisorInvitationStatus::PENDING, $expiresAt, null, $now, null);
    }

    public static function restore(string $id, string $swapPoolId, string $invitedByWorkerId, string $tokenHash, SupervisorInvitationStatus $status, DateTimeImmutable $expiresAt, ?string $respondedByUserId, DateTimeImmutable $createdAt, ?DateTimeImmutable $respondedAt): self
    {
        return new self($id, $swapPoolId, $invitedByWorkerId, $tokenHash, $status, $expiresAt, $respondedByUserId, $createdAt, $respondedAt);
    }

    /** False when the same person already accepted it: a double tap is not an error. */
    public function accept(string $userId, DateTimeImmutable $now): bool
    {
        if (SupervisorInvitationStatus::ACCEPTED === $this->status && $userId === $this->respondedByUserId) {
            return false;
        }
        $this->assertOpen($userId, $now);
        $this->status = SupervisorInvitationStatus::ACCEPTED;
        $this->respondedByUserId = $userId;
        $this->respondedAt = $now;

        return true;
    }

    public function decline(string $userId, DateTimeImmutable $now): bool
    {
        if (SupervisorInvitationStatus::DECLINED === $this->status && $userId === $this->respondedByUserId) {
            return false;
        }
        $this->assertOpen($userId, $now);
        $this->status = SupervisorInvitationStatus::DECLINED;
        $this->respondedByUserId = $userId;
        $this->respondedAt = $now;

        return true;
    }

    /** A newer link from the same colleague replaces this one. */
    public function supersede(DateTimeImmutable $now): void
    {
        if (SupervisorInvitationStatus::PENDING !== $this->status) {
            return;
        }
        $this->status = SupervisorInvitationStatus::SUPERSEDED;
        $this->respondedAt = $now;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return SupervisorInvitationStatus::PENDING === $this->status && $now >= $this->expiresAt;
    }

    public function isOpen(DateTimeImmutable $now): bool
    {
        return SupervisorInvitationStatus::PENDING === $this->status && $now < $this->expiresAt;
    }

    private function assertOpen(string $userId, DateTimeImmutable $now): void
    {
        if ('' === trim($userId)) {
            throw new InvalidArgumentException('Somebody has to answer the invitation.');
        }
        if (SupervisorInvitationStatus::PENDING !== $this->status) {
            throw new SupervisionRejected('Esta invitación ya no está disponible.');
        }
        if ($now >= $this->expiresAt) {
            throw new SupervisionRejected('Esta invitación ha caducado.');
        }
        if ($userId === $this->invitedByWorkerId) {
            throw new SupervisionRejected('No puedes responder a una invitación que has creado tú. Envíasela a vuestro responsable.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function swapPoolId(): string
    {
        return $this->swapPoolId;
    }

    public function invitedByWorkerId(): string
    {
        return $this->invitedByWorkerId;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function status(): SupervisorInvitationStatus
    {
        return $this->status;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function respondedByUserId(): ?string
    {
        return $this->respondedByUserId;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function respondedAt(): ?DateTimeImmutable
    {
        return $this->respondedAt;
    }
}
