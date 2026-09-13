<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class ExchangeBalance
{
    private function __construct(
        private readonly string $id,
        private readonly string $creditorWorkerId,
        private readonly string $owingWorkerId,
        private readonly string $sourceRequestId,
        private readonly string $sourceRosterDayId,
        private readonly int $earnedMinutes,
        private int $redeemedMinutes,
        private int $reservedMinutes,
        private ExchangeBalanceStatus $status,
        private readonly ?ReturnPreference $preference,
        private readonly DateTimeImmutable $createdAt,
        private readonly ?DateTimeImmutable $expiresAt,
        private DateTimeImmutable $updatedAt,
    ) {
        if ('' === trim($id) || '' === trim($creditorWorkerId) || '' === trim($owingWorkerId) || $creditorWorkerId === $owingWorkerId || $earnedMinutes <= 0 || $redeemedMinutes < 0 || $reservedMinutes < 0 || $redeemedMinutes + $reservedMinutes > $earnedMinutes) {
            throw new InvalidArgumentException('El saldo de intercambio no es válido.');
        }
    }

    public static function earn(string $id, string $creditorWorkerId, string $owingWorkerId, string $sourceRequestId, string $sourceRosterDayId, int $minutes, ?ReturnPreference $preference, DateTimeImmutable $now, ?DateTimeImmutable $expiresAt = null): self
    {
        return new self($id, $creditorWorkerId, $owingWorkerId, $sourceRequestId, $sourceRosterDayId, $minutes, 0, 0, ExchangeBalanceStatus::OPEN, $preference, $now, $expiresAt, $now);
    }

    public static function restore(string $id, string $creditorWorkerId, string $owingWorkerId, string $sourceRequestId, string $sourceRosterDayId, int $earnedMinutes, int $redeemedMinutes, int $reservedMinutes, ExchangeBalanceStatus $status, ?ReturnPreference $preference, DateTimeImmutable $createdAt, ?DateTimeImmutable $expiresAt, DateTimeImmutable $updatedAt): self
    {
        return new self($id, $creditorWorkerId, $owingWorkerId, $sourceRequestId, $sourceRosterDayId, $earnedMinutes, $redeemedMinutes, $reservedMinutes, $status, $preference, $createdAt, $expiresAt, $updatedAt);
    }

    public function reserve(int $minutes, DateTimeImmutable $now): void
    {
        if ($minutes <= 0 || $minutes > $this->availableMinutes() || !\in_array($this->status, [ExchangeBalanceStatus::OPEN, ExchangeBalanceStatus::PARTIALLY_REDEEMED], true)) {
            throw new InvalidArgumentException('No queda saldo suficiente para reservar ese turno.');
        }
        $this->reservedMinutes += $minutes;
        $this->updatedAt = $now;
    }

    public function releaseReservation(int $minutes, DateTimeImmutable $now): void
    {
        if ($minutes <= 0 || $minutes > $this->reservedMinutes) {
            throw new InvalidArgumentException('La reserva de saldo no es válida.');
        }
        $this->reservedMinutes -= $minutes;
        $this->updatedAt = $now;
    }

    public function redeemReserved(int $minutes, DateTimeImmutable $now): void
    {
        if ($minutes <= 0 || $minutes > $this->reservedMinutes) {
            throw new InvalidArgumentException('No existe esa reserva de saldo.');
        }
        $this->reservedMinutes -= $minutes;
        $this->redeemedMinutes += $minutes;
        $this->status = 0 === $this->remainingMinutes() ? ExchangeBalanceStatus::FULFILLED : ExchangeBalanceStatus::PARTIALLY_REDEEMED;
        $this->updatedAt = $now;
    }

    public function remainingMinutes(): int
    {
        return $this->earnedMinutes - $this->redeemedMinutes;
    }

    public function availableMinutes(): int
    {
        return $this->remainingMinutes() - $this->reservedMinutes;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function creditorWorkerId(): string
    {
        return $this->creditorWorkerId;
    }

    public function owingWorkerId(): string
    {
        return $this->owingWorkerId;
    }

    public function sourceRequestId(): string
    {
        return $this->sourceRequestId;
    }

    public function sourceRosterDayId(): string
    {
        return $this->sourceRosterDayId;
    }

    public function earnedMinutes(): int
    {
        return $this->earnedMinutes;
    }

    public function redeemedMinutes(): int
    {
        return $this->redeemedMinutes;
    }

    public function reservedMinutes(): int
    {
        return $this->reservedMinutes;
    }

    public function status(): ExchangeBalanceStatus
    {
        return $this->status;
    }

    public function preference(): ?ReturnPreference
    {
        return $this->preference;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
