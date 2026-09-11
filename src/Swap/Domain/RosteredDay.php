<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use App\SharedKernel\Domain\ShiftKind;

/**
 * One day of somebody's calendar, projected for Swap: enough to decide whether
 * it can be offered and to render the card, and nothing more. Swap never sees a
 * RosterDay, a ShiftSegment or a preset.
 */
final readonly class RosteredDay
{
    public function __construct(
        public string $assignmentId,
        public string $date,
        public RosteredDayState $state,
        public string $rosterDayId = '',
        public string $shiftLabel = '',
        public string $abbreviation = '',
        public string $startsAt = '',
        public string $endsAt = '',
        public bool $endsNextDay = false,
        public string $colorKey = 'slate',
        public ShiftKind $shiftKind = ShiftKind::OTHER,
    ) {
    }

    public static function unknown(string $assignmentId, string $date): self
    {
        return new self($assignmentId, $date, RosteredDayState::UNKNOWN);
    }

    public function isWorking(): bool
    {
        return RosteredDayState::WORKING === $this->state && '' !== $this->rosterDayId;
    }

    public function hours(): string
    {
        return '' === $this->startsAt ? '' : $this->startsAt.'–'.$this->endsAt;
    }

    /** The key both sides of the port agree on for a lookup. */
    public function key(): string
    {
        return self::keyFor($this->assignmentId, $this->date);
    }

    public static function keyFor(string $assignmentId, string $date): string
    {
        return $assignmentId.'|'.$date;
    }
}
