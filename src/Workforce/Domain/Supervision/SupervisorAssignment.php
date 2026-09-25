<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * "This person supervises the changes of this pool" — and whether the pool's
 * own workers have vouched for it yet.
 *
 * Authority is a property of one assignment, never of the account: the same
 * person can be verified for UCI, pending for Urgencias and a plain worker
 * everywhere else. The verifications live inside the aggregate so the quorum
 * is decided under the same lock that records the vote that reaches it.
 */
final class SupervisorAssignment
{
    /** @param list<SupervisorVerification> $verifications */
    private function __construct(
        private readonly string $id,
        private readonly string $supervisorUserId,
        private readonly string $swapPoolId,
        private readonly ?string $invitationId,
        private readonly string $verificationTokenHash,
        private SupervisorAssignmentStatus $status,
        private ?SupervisorVerificationLevel $verificationLevel,
        private array $verifications,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $verifiedAt,
        private ?DateTimeImmutable $leftAt,
    ) {
        if ('' === trim($id) || '' === trim($supervisorUserId) || '' === trim($swapPoolId) || 64 !== \strlen($verificationTokenHash)) {
            throw new InvalidArgumentException('A supervisor assignment needs a person, a pool and a verification link.');
        }
        if (SupervisorAssignmentStatus::VERIFIED === $status && (null === $verifiedAt || null === $verificationLevel)) {
            throw new InvalidArgumentException('A verified supervisor needs a verification level and date.');
        }
    }

    /** Accepting an invitation asks the team; it grants nothing by itself. */
    public static function requestVerification(string $id, string $supervisorUserId, string $swapPoolId, ?string $invitationId, string $verificationTokenHash, DateTimeImmutable $now): self
    {
        return new self($id, $supervisorUserId, $swapPoolId, $invitationId, $verificationTokenHash, SupervisorAssignmentStatus::PENDING_VERIFICATION, null, [], $now, null, null);
    }

    /** @param list<SupervisorVerification> $verifications */
    public static function restore(string $id, string $supervisorUserId, string $swapPoolId, ?string $invitationId, string $verificationTokenHash, SupervisorAssignmentStatus $status, ?SupervisorVerificationLevel $verificationLevel, array $verifications, DateTimeImmutable $createdAt, ?DateTimeImmutable $verifiedAt, ?DateTimeImmutable $leftAt): self
    {
        return new self($id, $supervisorUserId, $swapPoolId, $invitationId, $verificationTokenHash, $status, $verificationLevel, $verifications, $createdAt, $verifiedAt, $leftAt);
    }

    /**
     * Records one colleague's answer.
     *
     * Returns true only for the call that moves the assignment to VERIFIED, so
     * the caller publishes "verified" exactly once. Answering twice is harmless;
     * the only change of mind allowed is "I can't confirm" → "yes, it's them".
     */
    public function recordVerification(SupervisorVerification $verification, SwapPoolTeam $team, SupervisorVerificationPolicy $policy): bool
    {
        if ($team->swapPoolId !== $this->swapPoolId) {
            throw new InvalidArgumentException('The team does not belong to this assignment.');
        }
        if (!$this->status->isActive()) {
            throw new SupervisionRejected('Esta solicitud de responsable ya no está activa.');
        }
        if ($verification->verifierWorkerId === $this->supervisorUserId) {
            throw new SupervisionRejected('No puedes confirmarte a ti mismo como responsable.');
        }
        if (!$team->includes($verification->verifierWorkerId)) {
            throw new SupervisionRejected('No perteneces a este equipo y no puedes verificar a su responsable.');
        }

        $previous = $this->verificationBy($verification->verifierWorkerId);
        if (null !== $previous) {
            if ($previous->counts() || !$verification->counts()) {
                return false;
            }
            $this->verifications = array_values(array_map(
                static fn (SupervisorVerification $existing): SupervisorVerification => $existing === $previous
                    ? new SupervisorVerification($previous->id, $previous->verifierWorkerId, $verification->decision, $verification->source, $verification->createdAt)
                    : $existing,
                $this->verifications,
            ));
        } else {
            $this->verifications[] = $verification;
        }

        if (SupervisorAssignmentStatus::VERIFIED === $this->status || $this->confirmations() < $policy->requiredConfirmations($team, $this->supervisorUserId)) {
            return false;
        }
        $this->status = SupervisorAssignmentStatus::VERIFIED;
        $this->verificationLevel = SupervisorVerificationLevel::TEAM_VERIFIED;
        $this->verifiedAt = $verification->createdAt;

        return true;
    }

    /**
     * Stepping down, pending or verified. The row stays: approvals already
     * given still point at it. Returns false when there was nothing to change.
     */
    public function leave(string $userId, DateTimeImmutable $now): bool
    {
        if ($userId !== $this->supervisorUserId) {
            throw new SupervisionRejected('Solo la persona responsable puede renunciar.');
        }
        if (SupervisorAssignmentStatus::LEFT === $this->status) {
            return false;
        }
        if (SupervisorAssignmentStatus::REVOKED === $this->status) {
            throw new SupervisionRejected('Esta responsabilidad ya no está activa.');
        }
        $this->status = SupervisorAssignmentStatus::LEFT;
        $this->leftAt = $now;

        return true;
    }

    public function hasApprovalAuthority(): bool
    {
        return SupervisorAssignmentStatus::VERIFIED === $this->status;
    }

    public function confirmations(): int
    {
        return \count(array_filter($this->verifications, static fn (SupervisorVerification $verification): bool => $verification->counts()));
    }

    public function hasAnswered(string $workerId): bool
    {
        return null !== $this->verificationBy($workerId);
    }

    private function verificationBy(string $workerId): ?SupervisorVerification
    {
        foreach ($this->verifications as $verification) {
            if ($verification->verifierWorkerId === $workerId) {
                return $verification;
            }
        }

        return null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function supervisorUserId(): string
    {
        return $this->supervisorUserId;
    }

    public function swapPoolId(): string
    {
        return $this->swapPoolId;
    }

    public function invitationId(): ?string
    {
        return $this->invitationId;
    }

    public function verificationTokenHash(): string
    {
        return $this->verificationTokenHash;
    }

    public function status(): SupervisorAssignmentStatus
    {
        return $this->status;
    }

    public function verificationLevel(): ?SupervisorVerificationLevel
    {
        return $this->verificationLevel;
    }

    /** @return list<SupervisorVerification> */
    public function verifications(): array
    {
        return $this->verifications;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function verifiedAt(): ?DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function leftAt(): ?DateTimeImmutable
    {
        return $this->leftAt;
    }
}
