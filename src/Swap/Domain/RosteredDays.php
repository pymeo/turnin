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

    /**
     * Every stretch of scheduled work in the range, as real instants.
     *
     * Swap asks for this and not for days because a date cannot answer whether
     * two shifts collide: a morning and an evening share a date and do not, a
     * night shift and the next morning do not share one and do. Scheduling
     * materialises the instants because it owns the centre's time zone.
     *
     * @param list<string> $workerAssignmentIds
     *
     * @return list<RosteredShift>
     */
    public function shiftsInRange(array $workerAssignmentIds, string $from, string $to): array;

    /**
     * The stretches of work on exactly those days and no others. Used when the
     * days are already known — a list of published shifts — so that asking
     * about them never reads the rest of somebody else's rota.
     *
     * @param list<array{string, string}> $assignmentAndDatePairs
     *
     * @return list<RosteredShift>
     */
    public function shiftsFor(array $assignmentAndDatePairs): array;

    public function transferCoverage(string $fromAssignmentId, string $toAssignmentId, string $date): void;

    /**
     * Move both real shifts into the workers' current calendars. Source and
     * destination assignment ids differ when a professional edited their
     * labour profile after publishing a request.
     */
    public function exchange(string $firstSourceAssignmentId, string $firstDate, string $secondSourceAssignmentId, string $secondDate, string $firstCalendarAssignmentId, string $secondCalendarAssignmentId): void;
}
