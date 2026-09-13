<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class SwapProposal
{
    private function __construct(
        private readonly string $id,
        private readonly string $requestId,
        private readonly string $requestOwnerId,
        private readonly string $proposerId,
        private readonly string $proposerAssignmentId,
        private readonly SwapProposalKind $kind,
        private readonly ?string $offeredRosterDayId,
        private readonly ?WorkDate $offeredWorkDate,
        private readonly ?ReturnPreference $returnPreference,
        private SwapProposalStatus $status,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        if ('' === trim($id) || '' === trim($requestId) || '' === trim($requestOwnerId) || '' === trim($proposerId) || '' === trim($proposerAssignmentId) || $requestOwnerId === $proposerId) {
            throw new InvalidArgumentException('Una propuesta necesita dos profesionales y una solicitud válidos.');
        }
        $hasReturnShift = null !== $offeredRosterDayId && null !== $offeredWorkDate;
        if ((SwapProposalKind::EXCHANGE === $kind) !== $hasReturnShift) {
            throw new InvalidArgumentException('Un intercambio necesita un turno real de vuelta y una cobertura no puede llevarlo.');
        }
        if (SwapProposalKind::DEFERRED !== $kind && null !== $returnPreference) {
            throw new InvalidArgumentException('Solo un intercambio diferido puede guardar preferencias.');
        }
    }

    public static function propose(string $id, string $requestId, string $requestOwnerId, string $proposerId, string $proposerAssignmentId, SwapProposalKind $kind, ?string $offeredRosterDayId, ?WorkDate $offeredWorkDate, DateTimeImmutable $now, ?ReturnPreference $returnPreference = null): self
    {
        return new self($id, $requestId, $requestOwnerId, $proposerId, $proposerAssignmentId, $kind, $offeredRosterDayId, $offeredWorkDate, $returnPreference, SwapProposalStatus::PENDING, $now, $now);
    }

    public static function restore(string $id, string $requestId, string $requestOwnerId, string $proposerId, string $proposerAssignmentId, SwapProposalKind $kind, ?string $offeredRosterDayId, ?WorkDate $offeredWorkDate, ?ReturnPreference $returnPreference, SwapProposalStatus $status, DateTimeImmutable $createdAt, DateTimeImmutable $updatedAt): self
    {
        return new self($id, $requestId, $requestOwnerId, $proposerId, $proposerAssignmentId, $kind, $offeredRosterDayId, $offeredWorkDate, $returnPreference, $status, $createdAt, $updatedAt);
    }

    public function accept(string $workerId, DateTimeImmutable $now): void
    {
        $this->decide($workerId, SwapProposalStatus::ACCEPTED, $now);
    }

    public function reject(string $workerId, DateTimeImmutable $now): void
    {
        $this->decide($workerId, SwapProposalStatus::REJECTED, $now);
    }

    public function withdraw(string $workerId, DateTimeImmutable $now): void
    {
        if ($workerId !== $this->proposerId || SwapProposalStatus::PENDING !== $this->status) {
            throw new InvalidArgumentException('Esta propuesta ya no se puede retirar.');
        }
        $this->status = SwapProposalStatus::WITHDRAWN;
        $this->updatedAt = $now;
    }

    private function decide(string $workerId, SwapProposalStatus $status, DateTimeImmutable $now): void
    {
        if ($workerId !== $this->requestOwnerId || SwapProposalStatus::PENDING !== $this->status) {
            throw new InvalidArgumentException('Esta propuesta ya no se puede decidir.');
        }
        $this->status = $status;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function requestOwnerId(): string
    {
        return $this->requestOwnerId;
    }

    public function proposerId(): string
    {
        return $this->proposerId;
    }

    public function proposerAssignmentId(): string
    {
        return $this->proposerAssignmentId;
    }

    public function kind(): SwapProposalKind
    {
        return $this->kind;
    }

    public function offeredRosterDayId(): ?string
    {
        return $this->offeredRosterDayId;
    }

    public function offeredWorkDate(): ?WorkDate
    {
        return $this->offeredWorkDate;
    }

    public function returnPreference(): ?ReturnPreference
    {
        return $this->returnPreference;
    }

    public function status(): SwapProposalStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
