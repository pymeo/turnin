<?php

declare(strict_types=1);

namespace App\Swap\Domain;

use App\SharedKernel\Domain\ShiftKind;

interface Availabilities
{
    public function save(Availability $availability): void;

    public function byId(string $id): ?Availability;

    /** The one row per worker + pool + day + kind the unique index guarantees. */
    public function forSlot(string $workerId, string $swapPoolId, WorkDate $date, ShiftKind $shiftKind): ?Availability;

    /** @return list<Availability> Active slots for a worker on one date. */
    public function activeByWorkerOnDate(string $workerId, WorkDate $date): array;

    /** @return list<Availability> Active declarations from the given date on. */
    public function activeByWorker(string $workerId, WorkDate $from): array;

    /**
     * Who has offered to work that day in that group. Never includes the person
     * asking: a request's author is not a candidate for their own shift.
     *
     * @return list<Availability>
     */
    public function activeInPoolOnDate(string $swapPoolId, WorkDate $date, ShiftKind $shiftKind, string $excludingWorkerId): array;

    /**
     * The pools the worker is already available in on a day, so the calendar
     * can show the state without a query per group.
     *
     * @return list<string> swap pool ids
     */
    public function activePoolsOnDate(string $workerId, WorkDate $date): array;

    /**
     * The days in a range the worker has declared themselves available on.
     *
     * @return list<string> dates as YYYY-MM-DD
     */
    public function activeDatesFor(string $workerId, WorkDate $from, WorkDate $to): array;

    /**
     * The same answer for a whole screen of dates in one query — the changes
     * list marks every card the worker has already offered for.
     *
     * @param list<WorkDate> $dates
     *
     * @return array<string, list<string>> date|kind → swap pool ids
     */
    public function activePoolsOnDates(string $workerId, array $dates): array;
}
