<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use App\SharedKernel\Domain\ShiftKind;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * "I have this shift and I am looking for somebody who can do it.".
 *
 * One intention, deliberately. Giving a shift away, swapping it for another and
 * owing one back are three different agreements, but they all start from the
 * same sentence, and the shapes they take depend on a proposal flow that does
 * not exist yet. Splitting the intention before the outcomes exist would be
 * guessing at three sets of rules from zero examples.
 *
 * It references the rostered day by identity and copies nothing from it: the
 * shift still belongs to whoever opened the request, and publishing it does not
 * touch their calendar.
 */
final class SwapRequest
{
    private function __construct(
        private readonly string $id,
        private readonly string $workerId,
        private readonly string $workerAssignmentId,
        private readonly string $swapPoolId,
        private readonly string $rosterDayId,
        private readonly WorkDate $workDate,
        private readonly ShiftKind $shiftKind,
        private SwapRequestStatus $status,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        foreach ([$this->workerId, $this->workerAssignmentId, $this->swapPoolId, $this->rosterDayId] as $reference) {
            if ('' === trim($reference)) {
                throw new InvalidArgumentException('A swap request needs a worker, an assignment, a pool and the day it is about.');
            }
        }
    }

    /** @param WorkDate $today the worker's own today, in the time zone of their centre */
    public static function open(
        string $id,
        string $workerId,
        string $workerAssignmentId,
        string $swapPoolId,
        string $rosterDayId,
        WorkDate $workDate,
        ShiftKind $shiftKind,
        WorkDate $today,
        DateTimeImmutable $now,
    ): self {
        // Today is already being worked, so there is nobody to hand it to.
        if (!$workDate->isAfter($today)) {
            throw new InvalidArgumentException('Solo puedes publicar turnos futuros.');
        }

        return new self($id, $workerId, $workerAssignmentId, $swapPoolId, $rosterDayId, $workDate, $shiftKind, SwapRequestStatus::OPEN, $now, $now);
    }

    public static function restore(
        string $id,
        string $workerId,
        string $workerAssignmentId,
        string $swapPoolId,
        string $rosterDayId,
        WorkDate $workDate,
        ShiftKind $shiftKind,
        SwapRequestStatus $status,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $workerId, $workerAssignmentId, $swapPoolId, $rosterDayId, $workDate, $shiftKind, $status, $createdAt, $updatedAt);
    }

    /**
     * Withdrawing keeps the row. A cancelled request is a thing that happened,
     * and the first question anyone asks about a swap network is how often
     * people change their mind.
     */
    public function cancel(string $byWorkerId, DateTimeImmutable $now): void
    {
        if ($byWorkerId !== $this->workerId) {
            throw new InvalidArgumentException('Solo quien publica un turno puede retirarlo.');
        }
        if (SwapRequestStatus::OPEN !== $this->status) {
            throw new InvalidArgumentException('Esta solicitud ya no está activa.');
        }

        $this->status = SwapRequestStatus::CANCELLED;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function workerId(): string
    {
        return $this->workerId;
    }

    public function workerAssignmentId(): string
    {
        return $this->workerAssignmentId;
    }

    public function swapPoolId(): string
    {
        return $this->swapPoolId;
    }

    public function rosterDayId(): string
    {
        return $this->rosterDayId;
    }

    public function workDate(): WorkDate
    {
        return $this->workDate;
    }

    public function shiftKind(): ShiftKind
    {
        return $this->shiftKind;
    }

    public function status(): SwapRequestStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return SwapRequestStatus::OPEN === $this->status;
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
