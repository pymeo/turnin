<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * The roster days of one worker. Every method is range-scoped on purpose:
 * a calendar screen asks for one month, never for "everything", and an API
 * that makes loading a year easy is an API that will load a year.
 */
interface RosterDays
{
    /** @return list<RosterDay> Ordered by date. */
    public function inRange(string $workerAssignmentId, WorkDate $from, WorkDate $to): array;

    /** @param non-empty-list<string> $workerAssignmentIds
     * @return list<RosterDay>
     */
    public function inRangeForAssignments(array $workerAssignmentIds, WorkDate $from, WorkDate $to): array;

    public function onDate(string $workerAssignmentId, WorkDate $date): ?RosterDay;

    /**
     * Writes the whole batch or nothing. Applying a rotation must not be able
     * to leave half a quarter filled in.
     *
     * @param list<RosterDay> $days
     * @param list<WorkDate>  $datesToClear
     */
    public function apply(string $workerAssignmentId, array $days, array $datesToClear): void;

    /** The first day at or after $from that has any information. */
    public function firstFrom(string $workerAssignmentId, WorkDate $from, int $withinDays): ?RosterDay;

    public function countFor(string $workerAssignmentId): int;
}
