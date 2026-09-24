<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use App\SharedKernel\Domain\ShiftKind;
use DateTimeImmutable;

/**
 * One stretch of scheduled work, as real instants.
 *
 * `RosteredDay` answers "what does this day look like on a calendar". This
 * answers the only question a swap actually depends on: **when exactly is this
 * person working**. A date is not enough — a morning and an evening on the same
 * day do not collide, and a night shift belongs to the day it starts on while
 * occupying eight hours of the next one. Scheduling materialises the instants
 * because it owns the time zone; Swap only compares them.
 *
 * A roster day can hold several of these: split shifts and "turno + guardia"
 * are ordinary, so covering a day means being free for every stretch of it.
 */
final readonly class RosteredShift
{
    public function __construct(
        public string $assignmentId,
        public string $rosterDayId,
        /** The work date the shift belongs to, which is the day it starts on. */
        public string $date,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public string $label,
        public string $abbreviation,
        public string $startsAtLocal,
        public string $endsAtLocal,
        public bool $endsNextDay,
        public string $colorKey = 'slate',
        public ShiftKind $shiftKind = ShiftKind::OTHER,
    ) {
    }

    public function overlaps(self $other): bool
    {
        return $this->startsAt < $other->endsAt && $other->startsAt < $this->endsAt;
    }

    /**
     * Minutes of rest between two shifts that do not overlap. Negative is not
     * possible here: an overlap is a different answer and is asked first.
     */
    public function restMinutesTo(self $other): int
    {
        $gap = $other->startsAt >= $this->endsAt
            ? $other->startsAt->getTimestamp() - $this->endsAt->getTimestamp()
            : $this->startsAt->getTimestamp() - $other->endsAt->getTimestamp();

        return intdiv(max(0, $gap), 60);
    }

    public function durationMinutes(): int
    {
        return intdiv($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp(), 60);
    }

    public function hours(): string
    {
        return $this->startsAtLocal.'–'.$this->endsAtLocal;
    }

    public function durationLabel(): string
    {
        return ShiftDuration::label($this->durationMinutes());
    }

    /** The pair that identifies the day this shift belongs to. */
    public function dayKey(): string
    {
        return RosteredDay::keyFor($this->assignmentId, $this->date);
    }
}
