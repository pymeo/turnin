<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * A wall-clock time of day, minute precision. Deliberately not an instant: a
 * shift labelled 22:00 starts at 22:00 on the clock on the wall, in March and in
 * October alike. Binding it to a timestamp is what makes a DST weekend show the
 * night shift on the wrong day.
 */
final readonly class LocalTime implements Stringable
{
    private function __construct(public int $hour, public int $minute)
    {
    }

    public static function of(int $hour, int $minute): self
    {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new InvalidArgumentException('A local time runs from 00:00 to 23:59.');
        }

        return new self($hour, $minute);
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/D', trim($value), $parts)) {
            throw new InvalidArgumentException('A local time is written as HH:MM.');
        }

        return self::of((int) $parts[1], (int) $parts[2]);
    }

    public function __toString(): string
    {
        return \sprintf('%02d:%02d', $this->hour, $this->minute);
    }

    public function minuteOfDay(): int
    {
        return $this->hour * 60 + $this->minute;
    }

    public function equals(self $other): bool
    {
        return $this->minuteOfDay() === $other->minuteOfDay();
    }
}
