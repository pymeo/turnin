<?php

declare(strict_types=1);

namespace App\Coordination\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class ScheduleInvitation
{
    private function __construct(
        private readonly string $id,
        private readonly string $inviterId,
        private readonly string $tokenHash,
        private readonly DateTimeImmutable $expiresAt,
        private ScheduleInvitationStatus $status,
        private readonly DateTimeImmutable $createdAt,
        private ?string $acceptedBy,
        private ?DateTimeImmutable $acceptedAt,
        private ?DateTimeImmutable $revokedAt,
    ) {
        if ('' === trim($id) || '' === trim($inviterId) || 64 !== \strlen($tokenHash)) {
            throw new InvalidArgumentException('A schedule invitation needs valid identities.');
        }
        if ($expiresAt <= $createdAt) {
            throw new InvalidArgumentException('A schedule invitation must expire in the future.');
        }
    }

    public static function create(string $id, string $inviterId, string $tokenHash, DateTimeImmutable $expiresAt, DateTimeImmutable $now): self
    {
        return new self($id, $inviterId, $tokenHash, $expiresAt, ScheduleInvitationStatus::PENDING, $now, null, null, null);
    }

    public static function restore(string $id, string $inviterId, string $tokenHash, DateTimeImmutable $expiresAt, ScheduleInvitationStatus $status, DateTimeImmutable $createdAt, ?string $acceptedBy, ?DateTimeImmutable $acceptedAt, ?DateTimeImmutable $revokedAt): self
    {
        return new self($id, $inviterId, $tokenHash, $expiresAt, $status, $createdAt, $acceptedBy, $acceptedAt, $revokedAt);
    }

    public function accept(string $guestId, DateTimeImmutable $now): void
    {
        if ($guestId === $this->inviterId) {
            throw new InvalidArgumentException('No puedes vincular tu calendario contigo mismo.');
        }
        if (ScheduleInvitationStatus::PENDING !== $this->status) {
            throw new InvalidArgumentException('Esta invitación ya no está disponible.');
        }
        if ($now >= $this->expiresAt) {
            throw new InvalidArgumentException('Esta invitación ha caducado.');
        }
        $this->status = ScheduleInvitationStatus::ACCEPTED;
        $this->acceptedBy = $guestId;
        $this->acceptedAt = $now;
    }

    public function revoke(string $actorId, DateTimeImmutable $now): void
    {
        if ($actorId !== $this->inviterId || ScheduleInvitationStatus::PENDING !== $this->status) {
            throw new InvalidArgumentException('Esta invitación no puede revocarse.');
        }
        $this->status = ScheduleInvitationStatus::REVOKED;
        $this->revokedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function inviterId(): string
    {
        return $this->inviterId;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function status(): ScheduleInvitationStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function acceptedBy(): ?string
    {
        return $this->acceptedBy;
    }

    public function acceptedAt(): ?DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
