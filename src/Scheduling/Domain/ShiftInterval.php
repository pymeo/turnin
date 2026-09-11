<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The exact temporal truth of scheduled work. Labels, kinds, preset ids and
 * colours deliberately do not occur here.
 */
final readonly class ShiftInterval
{
    private function __construct(public DateTimeImmutable $startsAt, public DateTimeImmutable $endsAt)
    {
        if ($this->endsAt <= $this->startsAt) {
            throw new InvalidArgumentException('A shift interval must end after it starts.');
        }
    }

    public static function materialize(WorkDate $date, ShiftWindow $window, DateTimeZone $timeZone): self
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$window->start, $timeZone);
        $endDate = $window->endsNextDay() ? $date->plusDays(1) : $date;
        $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $endDate.' '.$window->end, $timeZone);
        if (false === $start || false === $end) {
            throw new InvalidArgumentException('The local shift interval cannot be materialized.');
        }

        return new self($start, $end);
    }

    public function overlaps(self $other): bool
    {
        return $this->startsAt < $other->endsAt && $other->startsAt < $this->endsAt;
    }

    public function durationInMinutes(): int
    {
        return (int) (($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp()) / 60);
    }
}
