<?php

declare(strict_types=1);

namespace App\Tests\Support\Swap;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\RosteredDayState;

final readonly class FixedRosteredDays implements RosteredDays
{
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

    /** @param array<string, RosteredDay> $days */
    public function with(array $days): self
    {
        return new self([...$this->days, ...$days]);
    }
}
