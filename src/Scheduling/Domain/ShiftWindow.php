<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * The hours a segment covers, expressed in the local wall clock of the
 * assignment's time zone.
 *
 * "Does it end tomorrow?" is *derived*, never stored: an end at or before the
 * start can only mean the next day, and 08:00→08:00 is the 24-hour on-call
 * shift. Storing the flag as well would let the two disagree, and a row where
 * they disagree has no correct interpretation.
 */
final readonly class ShiftWindow implements Stringable
{
    private function __construct(public LocalTime $start, public LocalTime $end)
    {
    }

    public static function between(LocalTime $start, LocalTime $end): self
    {
        return new self($start, $end);
    }

    public static function fromStrings(string $start, string $end): self
    {
        return new self(LocalTime::fromString($start), LocalTime::fromString($end));
    }

    public function __toString(): string
    {
        return $this->start.'–'.$this->end;
    }

    public function endsNextDay(): bool
    {
        return $this->end->minuteOfDay() <= $this->start->minuteOfDay();
    }

    public function durationInMinutes(): int
    {
        $minutes = $this->end->minuteOfDay() - $this->start->minuteOfDay();

        return $minutes > 0 ? $minutes : $minutes + 24 * 60;
    }

    /** True when the window covers any minute between 22:00 and 06:00. */
    public function coversNightHours(): bool
    {
        $start = $this->start->minuteOfDay();
        for ($offset = 0; $offset < $this->durationInMinutes(); ++$offset) {
            $minute = ($start + $offset) % (24 * 60);
            if ($minute >= 22 * 60 || $minute < 6 * 60) {
                return true;
            }
        }

        return false;
    }

    public function equals(self $other): bool
    {
        return $this->start->equals($other->start) && $this->end->equals($other->end);
    }

    public function overlaps(self $other): bool
    {
        $mine = $this->minutesCovered();
        foreach ($other->minutesCovered() as $minute => $_) {
            if (isset($mine[$minute])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, true> */
    private function minutesCovered(): array
    {
        $covered = [];
        $start = $this->start->minuteOfDay();
        for ($offset = 0; $offset < $this->durationInMinutes(); ++$offset) {
            $covered[$start + $offset] = true;
        }

        return $covered;
    }

    public function guardAgainstEmptyWindow(): void
    {
        if (0 === $this->durationInMinutes()) {
            throw new InvalidArgumentException('A shift segment cannot be zero minutes long.');
        }
    }
}
