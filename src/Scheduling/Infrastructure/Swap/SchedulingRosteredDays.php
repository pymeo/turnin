<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Swap;

use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\WorkDate;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\RosteredDayState;

/**
 * Scheduling answering what Swap needs to know about a calendar: is this day
 * worked, and what does the shift look like.
 *
 * It reads, and only reads. Publishing a shift or offering to cover one never
 * writes to a roster — the shift stays with whoever has it until an agreement
 * exists, and agreements are the next slice.
 */
final readonly class SchedulingRosteredDays implements RosteredDays
{
    public function __construct(private RosterDays $rosterDays)
    {
    }

    public function dayFor(string $workerAssignmentId, string $date): RosteredDay
    {
        $day = $this->rosterDays->onDate($workerAssignmentId, WorkDate::fromString($date));

        return null === $day ? RosteredDay::unknown($workerAssignmentId, $date) : $this->project($workerAssignmentId, $day);
    }

    public function daysFor(array $assignmentAndDatePairs): array
    {
        if ([] === $assignmentAndDatePairs) {
            return [];
        }

        $assignments = [];
        $dates = [];
        foreach ($assignmentAndDatePairs as [$assignmentId, $date]) {
            $assignments[$assignmentId] = true;
            $dates[] = $date;
        }
        sort($dates);

        // One range query for every assignment involved, then filtered to the
        // pairs that were asked for. A card list must not become a query per
        // card, and the roster repository is already range-scoped.
        $wanted = [];
        foreach ($assignmentAndDatePairs as [$assignmentId, $date]) {
            $wanted[RosteredDay::keyFor($assignmentId, $date)] = true;
        }

        /** @var non-empty-list<string> $assignmentIds */
        $assignmentIds = array_keys($assignments);
        $found = [];
        foreach ($this->rosterDays->inRangeForAssignments($assignmentIds, WorkDate::fromString($dates[0]), WorkDate::fromString($dates[\count($dates) - 1])) as $day) {
            $key = RosteredDay::keyFor($day->workerAssignmentId(), (string) $day->date());
            if (isset($wanted[$key])) {
                $found[$key] = $this->project($day->workerAssignmentId(), $day);
            }
        }

        return $found;
    }

    public function inRangeForAssignments(array $workerAssignmentIds, string $from, string $to): array
    {
        if ([] === $workerAssignmentIds) {
            return [];
        }

        return array_map(
            fn (RosterDay $day): RosteredDay => $this->project($day->workerAssignmentId(), $day),
            $this->rosterDays->inRangeForAssignments(
                array_values(array_unique($workerAssignmentIds)),
                WorkDate::fromString($from),
                WorkDate::fromString($to),
            ),
        );
    }

    private function project(string $assignmentId, RosterDay $day): RosteredDay
    {
        if ($day->isRest()) {
            return new RosteredDay($assignmentId, (string) $day->date(), RosteredDayState::REST);
        }

        $segment = $day->firstSegment();
        if (null === $segment) {
            return RosteredDay::unknown($assignmentId, (string) $day->date());
        }

        return new RosteredDay(
            $assignmentId,
            (string) $day->date(),
            RosteredDayState::WORKING,
            $day->id(),
            $segment->labelSnapshot,
            $segment->abbreviationSnapshot,
            (string) $segment->window->start,
            (string) $segment->window->end,
            $segment->endsNextDay(),
            $segment->colorSnapshot->value,
            $segment->kind,
        );
    }
}
