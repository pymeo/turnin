<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * "I will do your shift. Here are a few of mine — pick one.".
 *
 * A direct exchange is a negotiation with exactly two moves, and the second one
 * belongs to the person who published the request. That is why an exchange
 * carries **one to five** return options instead of a single shift: making the
 * proposer guess which of their days the other person can actually do turned a
 * two-message conversation into a round trip per attempt.
 *
 * The other kinds predate that flow and are not created from the worker's
 * screens any more; they stay because rows exist and because the balance slice
 * will need them. See docs/DECISIONS.md.
 */
final class SwapProposal
{
    public const int MAXIMUM_OPTIONS = 5;

    /** @param list<SwapProposalOption> $options */
    private function __construct(
        private readonly string $id,
        private readonly string $requestId,
        private readonly string $requestOwnerId,
        private readonly string $proposerId,
        private readonly string $proposerAssignmentId,
        private readonly SwapProposalKind $kind,
        private readonly array $options,
        private ?string $chosenOptionId,
        private readonly ?ReturnPreference $returnPreference,
        private readonly ?string $exchangeBalanceId,
        private readonly int $reservedMinutes,
        private SwapProposalStatus $status,
        private ?string $approvedBy,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        if ('' === trim($id) || '' === trim($requestId) || '' === trim($requestOwnerId) || '' === trim($proposerId) || '' === trim($proposerAssignmentId) || $requestOwnerId === $proposerId) {
            throw new InvalidArgumentException('Una propuesta necesita dos profesionales y una solicitud válidos.');
        }
        if ((SwapProposalKind::EXCHANGE === $kind) !== ([] !== $options)) {
            throw new InvalidArgumentException('Un intercambio ofrece turnos de vuelta y los demás tipos no pueden llevarlos.');
        }
        if (\count($options) > self::MAXIMUM_OPTIONS) {
            throw new InvalidArgumentException(\sprintf('Puedes ofrecer como mucho %d turnos.', self::MAXIMUM_OPTIONS));
        }
        $keys = array_map(static fn (SwapProposalOption $option): string => $option->key(), $options);
        if (\count($keys) !== \count(array_unique($keys))) {
            throw new InvalidArgumentException('No puedes ofrecer el mismo turno dos veces.');
        }
        if (null !== $chosenOptionId && null === $this->optionById($chosenOptionId)) {
            throw new InvalidArgumentException('El turno elegido no es una de las opciones ofrecidas.');
        }
        if (SwapProposalKind::DEFERRED !== $kind && null !== $returnPreference) {
            throw new InvalidArgumentException('Solo un intercambio diferido puede guardar preferencias.');
        }
        $isRedemption = SwapProposalKind::REDEMPTION === $kind;
        if ($isRedemption !== (null !== $exchangeBalanceId && $reservedMinutes > 0) || (!$isRedemption && (null !== $exchangeBalanceId || 0 !== $reservedMinutes))) {
            throw new InvalidArgumentException('La redención necesita un saldo y una reserva válidos.');
        }
    }

    /** @param non-empty-list<SwapProposalOption> $options */
    public static function proposeExchange(string $id, string $requestId, string $requestOwnerId, string $proposerId, string $proposerAssignmentId, array $options, DateTimeImmutable $now): self
    {
        if ([] === $options) {
            throw new InvalidArgumentException('Elige al menos un turno tuyo para ofrecer a cambio.');
        }

        return new self($id, $requestId, $requestOwnerId, $proposerId, $proposerAssignmentId, SwapProposalKind::EXCHANGE, array_values($options), null, null, null, 0, SwapProposalStatus::PENDING, null, $now, $now);
    }

    public static function propose(string $id, string $requestId, string $requestOwnerId, string $proposerId, string $proposerAssignmentId, SwapProposalKind $kind, DateTimeImmutable $now, ?ReturnPreference $returnPreference = null): self
    {
        if (SwapProposalKind::EXCHANGE === $kind) {
            throw new InvalidArgumentException('Un intercambio se propone con sus turnos de vuelta.');
        }

        return new self($id, $requestId, $requestOwnerId, $proposerId, $proposerAssignmentId, $kind, [], null, $returnPreference, null, 0, SwapProposalStatus::PENDING, null, $now, $now);
    }

    public static function proposeRedemption(string $id, string $requestId, string $requestOwnerId, string $proposerId, string $proposerAssignmentId, string $exchangeBalanceId, int $reservedMinutes, DateTimeImmutable $now): self
    {
        return new self($id, $requestId, $requestOwnerId, $proposerId, $proposerAssignmentId, SwapProposalKind::REDEMPTION, [], null, null, $exchangeBalanceId, $reservedMinutes, SwapProposalStatus::PENDING, null, $now, $now);
    }

    /** @param list<SwapProposalOption> $options */
    public static function restore(string $id, string $requestId, string $requestOwnerId, string $proposerId, string $proposerAssignmentId, SwapProposalKind $kind, array $options, ?string $chosenOptionId, ?ReturnPreference $returnPreference, ?string $exchangeBalanceId, int $reservedMinutes, SwapProposalStatus $status, ?string $approvedBy, DateTimeImmutable $createdAt, DateTimeImmutable $updatedAt): self
    {
        return new self($id, $requestId, $requestOwnerId, $proposerId, $proposerAssignmentId, $kind, $options, $chosenOptionId, $returnPreference, $exchangeBalanceId, $reservedMinutes, $status, $approvedBy, $createdAt, $updatedAt);
    }

    /**
     * The request owner picks which of the offered shifts they will take on.
     * What happens next — straight to the rosters, or to a supervisor — is the
     * pool's policy and not the aggregate's business.
     */
    public function chooseOption(string $workerId, string $optionId, DateTimeImmutable $now): void
    {
        if (SwapProposalStatus::PENDING !== $this->status) {
            throw new InvalidArgumentException('Esta propuesta ya no se puede decidir.');
        }
        $this->choose($workerId, $optionId);
        $this->updatedAt = $now;
    }

    public function awaitApproval(string $workerId, ?string $optionId, DateTimeImmutable $now): void
    {
        $this->choose($workerId, $optionId);
        if (SwapProposalKind::EXCHANGE === $this->kind && null === $this->chosenOptionId) {
            throw new InvalidArgumentException('Elige uno de los turnos ofrecidos.');
        }
        $this->decide($workerId, SwapProposalStatus::PENDING_APPROVAL, $now);
    }

    public function execute(string $workerId, ?string $optionId, DateTimeImmutable $now, ?string $approvedBy = null): void
    {
        if ($workerId !== $this->requestOwnerId || !\in_array($this->status, [SwapProposalStatus::PENDING, SwapProposalStatus::PENDING_APPROVAL], true)) {
            throw new InvalidArgumentException('Esta propuesta ya no se puede ejecutar.');
        }
        $this->choose($workerId, $optionId);
        if (SwapProposalKind::EXCHANGE === $this->kind && null === $this->chosenOptionId) {
            throw new InvalidArgumentException('Elige uno de los turnos ofrecidos.');
        }
        $this->status = SwapProposalStatus::EXECUTED;
        $this->approvedBy = $approvedBy;
        $this->updatedAt = $now;
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

    /** Another proposal won the request, so this one can never happen. */
    public function expire(DateTimeImmutable $now): void
    {
        if (!\in_array($this->status, [SwapProposalStatus::PENDING, SwapProposalStatus::PENDING_APPROVAL], true)) {
            return;
        }
        $this->status = SwapProposalStatus::EXPIRED;
        $this->updatedAt = $now;
    }

    public function rejectApproval(DateTimeImmutable $now): void
    {
        if (SwapProposalStatus::PENDING_APPROVAL !== $this->status) {
            throw new InvalidArgumentException('Esta propuesta no está pendiente de aprobación.');
        }
        $this->status = SwapProposalStatus::APPROVAL_REJECTED;
        $this->updatedAt = $now;
    }

    private function choose(string $workerId, ?string $optionId): void
    {
        if (null === $optionId) {
            return;
        }
        if ($workerId !== $this->requestOwnerId) {
            throw new InvalidArgumentException('Solo quien publicó el turno elige qué recibe a cambio.');
        }
        if (null === $this->optionById($optionId)) {
            throw new InvalidArgumentException('Ese turno ya no es una de las opciones ofrecidas.');
        }
        $this->chosenOptionId = $optionId;
    }

    private function decide(string $workerId, SwapProposalStatus $status, DateTimeImmutable $now): void
    {
        if ($workerId !== $this->requestOwnerId || SwapProposalStatus::PENDING !== $this->status) {
            throw new InvalidArgumentException('Esta propuesta ya no se puede decidir.');
        }
        $this->status = $status;
        $this->updatedAt = $now;
    }

    public function optionById(string $optionId): ?SwapProposalOption
    {
        foreach ($this->options as $option) {
            if ($option->id === $optionId) {
                return $option;
            }
        }

        return null;
    }

    public function chosenOption(): ?SwapProposalOption
    {
        return null === $this->chosenOptionId ? null : $this->optionById($this->chosenOptionId);
    }

    /** @return list<SwapProposalOption> */
    public function options(): array
    {
        return $this->options;
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

    public function chosenOptionId(): ?string
    {
        return $this->chosenOptionId;
    }

    public function returnPreference(): ?ReturnPreference
    {
        return $this->returnPreference;
    }

    public function exchangeBalanceId(): ?string
    {
        return $this->exchangeBalanceId;
    }

    public function reservedMinutes(): int
    {
        return $this->reservedMinutes;
    }

    public function approvedBy(): ?string
    {
        return $this->approvedBy;
    }

    public function status(): SwapProposalStatus
    {
        return $this->status;
    }

    public function isLive(): bool
    {
        return \in_array($this->status, [SwapProposalStatus::PENDING, SwapProposalStatus::PENDING_APPROVAL], true);
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
