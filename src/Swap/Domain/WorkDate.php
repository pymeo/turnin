<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * The working day a request or an availability is about.
 *
 * Scheduling has a value object with the same name and the same meaning. It is
 * deliberately not shared: contexts integrate through ports, not through each
 * other's classes, and the day this one has to answer questions about — "is it
 * still in the future?" — is a Swap question. The vocabulary is shared; the
 * class is not. See docs/CONTEXT_MAP.md.
 */
final readonly class WorkDate implements Stringable
{
    private function __construct(public int $year, public int $month, public int $day)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', trim($value), $parts)) {
            throw new InvalidArgumentException('Una fecha se escribe como YYYY-MM-DD.');
        }

        $year = (int) $parts[1];
        $month = (int) $parts[2];
        $day = (int) $parts[3];
        if ($month < 1 || $month > 12 || $day < 1 || $day > self::daysIn($year, $month)) {
            throw new InvalidArgumentException(\sprintf('%s no es una fecha real.', $value));
        }

        return new self($year, $month, $day);
    }

    public function __toString(): string
    {
        return \sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }

    public static function fromDayNumber(int $dayNumber): self
    {
        $a = $dayNumber + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $d = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $d, 4);
        $m = intdiv(5 * $e + 2, 153);

        return new self(
            100 * $b + $d - 4800 + intdiv($m, 10),
            $m + 3 - 12 * intdiv($m, 10),
            $e - intdiv(153 * $m + 2, 5) + 1,
        );
    }

    /**
     * Integer arithmetic over Julian day numbers, like Scheduling's: no zone
     * and no daylight saving can move a date that never becomes an instant.
     */
    public function dayNumber(): int
    {
        $a = intdiv(14 - $this->month, 12);
        $y = $this->year + 4800 - $a;
        $m = $this->month + 12 * $a - 3;

        return $this->day + intdiv(153 * $m + 2, 5) + 365 * $y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) - 32045;
    }

    public function plusDays(int $days): self
    {
        return self::fromDayNumber($this->dayNumber() + $days);
    }

    /** ISO-8601: 1 is Monday, 7 is Sunday. */
    public function dayOfWeek(): int
    {
        return $this->dayNumber() % 7 + 1;
    }

    public function isAfter(self $other): bool
    {
        return (string) $this > (string) $other;
    }

    public function equals(self $other): bool
    {
        return (string) $this === (string) $other;
    }

    private static function daysIn(int $year, int $month): int
    {
        if (2 === $month) {
            return 0 === $year % 4 && (0 !== $year % 100 || 0 === $year % 400) ? 29 : 28;
        }

        return \in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }
}
