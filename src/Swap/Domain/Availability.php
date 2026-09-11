<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use App\SharedKernel\Domain\ShiftKind;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * "On the 18th I can work a morning shift in this group.".
 *
 * An explicit statement by the worker, and never inferred from the calendar. In
 * Turnin a day with no roster entry means *we do not know*, not *free*, so
 * reading an empty cell as availability would put somebody's rest day in front
 * of strangers. That rule is the reason this context exists separately from
 * Scheduling — see docs/DOMAIN.md.
 *
 * There are no degrees yet ("quiero" / "podría"). Withdrawal is `active`, and
 * grades arrive as a column with a default when there is a matcher that can do
 * something with the difference.
 */
final class Availability
{
    private function __construct(
        private readonly string $id,
        private readonly string $workerId,
        private readonly string $workerAssignmentId,
        private readonly string $swapPoolId,
        private readonly WorkDate $workDate,
        private readonly ShiftKind $shiftKind,
        private bool $active,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        foreach ([$this->workerId, $this->workerAssignmentId, $this->swapPoolId] as $reference) {
            if ('' === trim($reference)) {
                throw new InvalidArgumentException('An availability needs a worker, an assignment and a pool.');
            }
        }
    }

    public static function declare(
        string $id,
        string $workerId,
        string $workerAssignmentId,
        string $swapPoolId,
        WorkDate $workDate,
        ShiftKind $shiftKind,
        WorkDate $today,
        DateTimeImmutable $now,
    ): self {
        if (!$workDate->isAfter($today)) {
            throw new InvalidArgumentException('Solo puedes ofrecerte para días futuros.');
        }

        return new self($id, $workerId, $workerAssignmentId, $swapPoolId, $workDate, $shiftKind, true, $now, $now);
    }

    public static function restore(
        string $id,
        string $workerId,
        string $workerAssignmentId,
        string $swapPoolId,
        WorkDate $workDate,
        ShiftKind $shiftKind,
        bool $active,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $workerId, $workerAssignmentId, $swapPoolId, $workDate, $shiftKind, $active, $createdAt, $updatedAt);
    }

    /** Declaring twice is the same statement, not two of them. */
    public function reaffirm(DateTimeImmutable $now): void
    {
        if ($this->active) {
            return;
        }

        $this->active = true;
        $this->updatedAt = $now;
    }

    public function withdraw(string $byWorkerId, DateTimeImmutable $now): void
    {
        if ($byWorkerId !== $this->workerId) {
            throw new InvalidArgumentException('Solo puedes retirar tu propia disponibilidad.');
        }
        if (!$this->active) {
            return;
        }

        $this->active = false;
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

    public function workDate(): WorkDate
    {
        return $this->workDate;
    }

    public function shiftKind(): ShiftKind
    {
        return $this->shiftKind;
    }

    public function isActive(): bool
    {
        return $this->active;
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
