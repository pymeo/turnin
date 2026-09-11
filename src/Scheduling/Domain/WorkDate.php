<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * The working day a shift is booked against — a calendar date with no time and
 * no zone. A night shift that starts on Saturday at 22:00 and ends on Sunday at
 * 08:00 is a *Saturday* shift; confusing that with an instant is what silently
 * shifts whole rosters by one day.
 *
 * The arithmetic is integer arithmetic over Julian day numbers rather than
 * DateTimeImmutable, for two reasons. It is exact — no zone, no DST, no hour
 * can move a date — and it keeps Domain free of the wall-clock constructors
 * that tests/Architecture/DeterministicTimeTest forbids.
 */
final readonly class WorkDate implements Stringable
{
    private function __construct(public int $year, public int $month, public int $day)
    {
    }

    public static function of(int $year, int $month, int $day): self
    {
        if ($year < 1970 || $year > 2200) {
            throw new InvalidArgumentException('A work date must fall between 1970 and 2200.');
        }
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('A work date needs a month between 1 and 12.');
        }
        if ($day < 1 || $day > self::daysIn($year, $month)) {
            throw new InvalidArgumentException(\sprintf('%d is not a day of %04d-%02d.', $day, $year, $month));
        }

        return new self($year, $month, $day);
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', trim($value), $parts)) {
            throw new InvalidArgumentException('A work date is written as YYYY-MM-DD.');
        }

        return self::of((int) $parts[1], (int) $parts[2], (int) $parts[3]);
    }

    /**
     * The inverse of {@see dayNumber()}. Only ever fed a number this class
     * produced, which is why it can skip validation the constructor already did.
     */
    public static function fromDayNumber(int $dayNumber): self
    {
        $a = $dayNumber + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $d = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $d, 4);
        $m = intdiv(5 * $e + 2, 153);

        return self::of(
            100 * $b + $d - 4800 + intdiv($m, 10),
            $m + 3 - 12 * intdiv($m, 10),
            $e - intdiv(153 * $m + 2, 5) + 1,
        );
    }

    public function __toString(): string
    {
        return \sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }

    /** Days elapsed since the Julian epoch: the basis of every comparison here. */
    public function dayNumber(): int
    {
        $a = intdiv(14 - $this->month, 12);
        $y = $this->year + 4800 - $a;
        $m = $this->month + 12 * $a - 3;

        return $this->day
            + intdiv(153 * $m + 2, 5)
            + 365 * $y
            + intdiv($y, 4)
            - intdiv($y, 100)
            + intdiv($y, 400)
            - 32045;
    }

    public function plusDays(int $days): self
    {
        return self::fromDayNumber($this->dayNumber() + $days);
    }

    public function daysUntil(self $other): int
    {
        return $other->dayNumber() - $this->dayNumber();
    }

    public function equals(self $other): bool
    {
        return $this->dayNumber() === $other->dayNumber();
    }

    public function isBefore(self $other): bool
    {
        return $this->dayNumber() < $other->dayNumber();
    }

    public function isAfter(self $other): bool
    {
        return $this->dayNumber() > $other->dayNumber();
    }

    /** ISO-8601: 1 is Monday, 7 is Sunday. */
    public function dayOfWeek(): int
    {
        return $this->dayNumber() % 7 + 1;
    }

    public function firstOfMonth(): self
    {
        return self::of($this->year, $this->month, 1);
    }

    public function lastOfMonth(): self
    {
        return self::of($this->year, $this->month, self::daysIn($this->year, $this->month));
    }

    public function month(): RosterMonth
    {
        return RosterMonth::of($this->year, $this->month);
    }

    public static function daysIn(int $year, int $month): int
    {
        if (2 === $month) {
            return self::isLeapYear($year) ? 29 : 28;
        }

        return \in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }

    private static function isLeapYear(int $year): bool
    {
        return 0 === $year % 4 && (0 !== $year % 100 || 0 === $year % 400);
    }
}
