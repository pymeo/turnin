<?php

declare(strict_types=1);

namespace App\Tests\Support\Swap;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\RosteredDayState;
use App\Swap\Domain\RosteredShift;
use App\Swap\Domain\WorkDate;
use DateTimeImmutable;
use DateTimeZone;

final readonly class FixedRosteredDays implements RosteredDays
{
    /** Every fixture rota lives on the peninsula unless a test says otherwise. */
    private const string ZONE_ID = 'Europe/Madrid';

    /** @param array<string, RosteredDay> $days keyed by {@see RosteredDay::keyFor()} */
    public function __construct(private array $days = [])
    {
    }

    public static function working(string $assignmentId, string $date, string $rosterDayId = 'roster-day'): self
    {
        return new self([RosteredDay::keyFor($assignmentId, $date) => new RosteredDay(
            $assignmentId,
            $date,
            RosteredDayState::WORKING,
            $rosterDayId,
            'Noche',
            'N',
            '22:00',
            '08:00',
            true,
            'blue',
            ShiftKind::NIGHT,
        )]);
    }

    public static function rest(string $assignmentId, string $date): self
    {
        return new self([RosteredDay::keyFor($assignmentId, $date) => new RosteredDay(
            $assignmentId,
            $date,
            RosteredDayState::REST,
        )]);
    }

    public function dayFor(string $workerAssignmentId, string $date): RosteredDay
    {
        return $this->days[RosteredDay::keyFor($workerAssignmentId, $date)] ?? RosteredDay::unknown($workerAssignmentId, $date);
    }

    public function daysFor(array $assignmentAndDatePairs): array
    {
        $found = [];
        foreach ($assignmentAndDatePairs as [$assignmentId, $date]) {
            $key = RosteredDay::keyFor($assignmentId, $date);
            if (isset($this->days[$key])) {
                $found[$key] = $this->days[$key];
            }
        }

        return $found;
    }

    public function inRangeForAssignments(array $workerAssignmentIds, string $from, string $to): array
    {
        return array_values(array_filter(
            $this->days,
            static fn (RosteredDay $day): bool => \in_array($day->assignmentId, $workerAssignmentIds, true) && $day->date >= $from && $day->date <= $to,
        ));
    }

    public function shiftsInRange(array $workerAssignmentIds, string $from, string $to): array
    {
        $zone = new DateTimeZone(self::ZONE_ID);
        $shifts = [];
        foreach ($this->inRangeForAssignments($workerAssignmentIds, $from, $to) as $day) {
            if (!$day->isWorking()) {
                continue;
            }
            $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $day->date.' '.$day->startsAt, $zone);
            $endDate = $day->endsNextDay ? WorkDate::fromString($day->date)->plusDays(1) : WorkDate::fromString($day->date);
            $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $endDate.' '.$day->endsAt, $zone);
            if (false === $start || false === $end) {
                continue;
            }
            $shifts[] = new RosteredShift(
                $day->assignmentId,
                $day->rosterDayId,
                $day->date,
                $start,
                $end,
                $day->shiftLabel,
                $day->abbreviation,
                $day->startsAt,
                $day->endsAt,
                $day->endsNextDay,
                $day->colorKey,
                $day->shiftKind,
            );
        }

        return $shifts;
    }

    public function shiftsFor(array $assignmentAndDatePairs): array
    {
        $wanted = [];
        foreach ($assignmentAndDatePairs as [$assignmentId, $date]) {
            $wanted[RosteredDay::keyFor($assignmentId, $date)] = true;
        }

        return array_values(array_filter(
            $this->shiftsInRange(array_map(static fn (array $pair): string => $pair[0], $assignmentAndDatePairs), '0000-01-01', '9999-12-31'),
            static fn (RosteredShift $shift): bool => isset($wanted[$shift->dayKey()]),
        ));
    }

    /** @param array<string, RosteredDay> $days */
    public function with(array $days): self
    {
        return new self([...$this->days, ...$days]);
    }

    public function transferCoverage(string $fromAssignmentId, string $toAssignmentId, string $date): void
    {
    }

    public function exchange(string $firstSourceAssignmentId, string $firstDate, string $secondSourceAssignmentId, string $secondDate, string $firstCalendarAssignmentId, string $secondCalendarAssignmentId): void
    {
    }
}
