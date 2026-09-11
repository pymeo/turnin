<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * A calendar month. The unit the calendar screen loads, navigates and
 * summarises by — never a whole year (see docs/ROADMAP.md § performance).
 */
final readonly class RosterMonth implements Stringable
{
    private function __construct(public int $year, public int $month)
    {
    }

    public static function of(int $year, int $month): self
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('A month is a number between 1 and 12.');
        }

        return new self($year, $month);
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})$/D', trim($value), $parts)) {
            throw new InvalidArgumentException('A month is written as YYYY-MM.');
        }

        return self::of((int) $parts[1], (int) $parts[2]);
    }

    public function __toString(): string
    {
        return \sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function firstDay(): WorkDate
    {
        return WorkDate::of($this->year, $this->month, 1);
    }

    public function lastDay(): WorkDate
    {
        return WorkDate::of($this->year, $this->month, WorkDate::daysIn($this->year, $this->month));
    }

    public function length(): int
    {
        return WorkDate::daysIn($this->year, $this->month);
    }

    public function next(): self
    {
        return 12 === $this->month ? self::of($this->year + 1, 1) : self::of($this->year, $this->month + 1);
    }

    public function previous(): self
    {
        return 1 === $this->month ? self::of($this->year - 1, 12) : self::of($this->year, $this->month - 1);
    }

    public function plusMonths(int $months): self
    {
        $total = $this->year * 12 + ($this->month - 1) + $months;

        return self::of(intdiv($total, 12), $total % 12 + 1);
    }

    public function contains(WorkDate $date): bool
    {
        return $date->year === $this->year && $date->month === $this->month;
    }

    public function dayOrNull(int $day): ?WorkDate
    {
        return $day >= 1 && $day <= $this->length() ? WorkDate::of($this->year, $this->month, $day) : null;
    }
}
