<?php

declare(strict_types=1);

namespace App\Swap\Domain;

/**
 * Declared by Swap, implemented by Scheduling.
 *
 * Swap needs two answers about a calendar and no more: can this day be offered,
 * and what does the shift look like on a card. Publishing never writes to a
 * roster — see docs/adr/0010-swap-requests-and-availability.md.
 */
interface RosteredDays
{
    public function dayFor(string $workerAssignmentId, string $date): RosteredDay;

    /**
     * Resolved in one query, because a list of twenty requests must not become
     * twenty lookups.
     *
     * @param list<array{string, string}> $assignmentAndDatePairs
     *
     * @return array<string, RosteredDay> keyed by {@see RosteredDay::keyFor()}
     */
    public function daysFor(array $assignmentAndDatePairs): array;

    /**
     * @param list<string> $workerAssignmentIds
     *
     * @return list<RosteredDay>
     */
    public function inRangeForAssignments(array $workerAssignmentIds, string $from, string $to): array;

    public function transferCoverage(string $fromAssignmentId, string $toAssignmentId, string $date): void;

    public function exchange(string $firstAssignmentId, string $firstDate, string $secondAssignmentId, string $secondDate): void;
}
