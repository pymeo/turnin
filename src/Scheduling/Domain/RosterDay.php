<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One day of one worker's roster, and the consistency boundary of this context.
 *
 * A day exists only once the worker has said something about it. No row means
 * UNKNOWN, and that is the whole point of the design: the alternative — a table
 * pre-filled with "unknown" rows, or an empty cell read as "off" — is what makes
 * a scheduling product offer shifts on days nobody ever confirmed.
 */
final class RosterDay
{
    /** @param list<ShiftSegment> $segments */
    private function __construct(
        private readonly string $id,
        private readonly string $workerAssignmentId,
        private readonly WorkDate $date,
        private RosterDayState $state,
        private array $segments,
        private RosterSource $source,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        $this->guardConsistency();
    }

    public static function rest(string $id, string $workerAssignmentId, WorkDate $date, RosterSource $source, DateTimeImmutable $now): self
    {
        return new self($id, $workerAssignmentId, $date, RosterDayState::REST, [], $source, $now, $now);
    }

    /** @param list<ShiftSegment> $segments */
    public static function working(string $id, string $workerAssignmentId, WorkDate $date, array $segments, RosterSource $source, DateTimeImmutable $now): self
    {
        return new self($id, $workerAssignmentId, $date, RosterDayState::WORKING, $segments, $source, $now, $now);
    }

    /**
     * Rehydration for the repository. Application code builds days through
     * {@see rest()} and {@see working()}; this exists so persistence does not
     * have to fake a creation instant.
     *
     * @param list<ShiftSegment> $segments
     */
    public static function restore(string $id, string $workerAssignmentId, WorkDate $date, RosterDayState $state, array $segments, RosterSource $source, DateTimeImmutable $createdAt, DateTimeImmutable $updatedAt): self
    {
        return new self($id, $workerAssignmentId, $date, $state, $segments, $source, $createdAt, $updatedAt);
    }

    public function markRest(RosterSource $source, DateTimeImmutable $now): void
    {
        $this->state = RosterDayState::REST;
        $this->segments = [];
        $this->source = $source;
        $this->updatedAt = $now;
        $this->guardConsistency();
    }

    /** @param list<ShiftSegment> $segments */
    public function assign(array $segments, RosterSource $source, DateTimeImmutable $now): void
    {
        $this->state = RosterDayState::WORKING;
        $this->segments = $segments;
        $this->source = $source;
        $this->updatedAt = $now;
        $this->guardConsistency();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function workerAssignmentId(): string
    {
        return $this->workerAssignmentId;
    }

    public function date(): WorkDate
    {
        return $this->date;
    }

    public function state(): RosterDayState
    {
        return $this->state;
    }

    public function isWorking(): bool
    {
        return RosterDayState::WORKING === $this->state;
    }

    public function isRest(): bool
    {
        return RosterDayState::REST === $this->state;
    }

    /** @return list<ShiftSegment> */
    public function segments(): array
    {
        return $this->segments;
    }

    public function firstSegment(): ?ShiftSegment
    {
        return $this->segments[0] ?? null;
    }

    /** The two or three characters a calendar cell can actually fit. */
    public function abbreviation(): string
    {
        if ($this->isRest()) {
            return 'L';
        }

        $segment = $this->firstSegment();

        return null === $segment ? '' : $segment->abbreviationSnapshot;
    }

    public function coversNightHours(): bool
    {
        foreach ($this->segments as $segment) {
            if ($segment->window->coversNightHours()) {
                return true;
            }
        }

        return false;
    }

    public function source(): RosterSource
    {
        return $this->source;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function guardConsistency(): void
    {
        if ('' === trim($this->workerAssignmentId)) {
            throw new InvalidArgumentException('A roster day belongs to a worker assignment.');
        }
        if (RosterDayState::REST === $this->state && [] !== $this->segments) {
            throw new InvalidArgumentException('A rest day cannot carry shift segments.');
        }
        if (RosterDayState::WORKING === $this->state && [] === $this->segments) {
            throw new InvalidArgumentException('A working day needs at least one shift segment.');
        }

        foreach ($this->segments as $index => $segment) {
            foreach (\array_slice($this->segments, $index + 1) as $other) {
                if ($segment->window->overlaps($other->window)) {
                    throw new InvalidArgumentException(\sprintf('The segments %s and %s overlap on %s.', $segment->window, $other->window, $this->date));
                }
            }
        }
    }
}
