<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use DateTimeZone;

/**
 * Declared here and implemented by Workforce, which owns the data. Scheduling
 * never reaches into another context's classes; it states what it needs and
 * the supplier provides it (see docs/CONTEXT_MAP.md).
 */
interface AssignedWorkers
{
    public function primaryFor(string $workerId): ?AssignedWorker;

    /** @return list<AssignedWorker> */
    public function activeFor(string $workerId): array;

    public function byIdFor(string $workerId, string $assignmentId): ?AssignedWorker;

    /**
     * The centre's time zone for each assignment, in one query.
     *
     * Turning a roster into real instants needs it, and doing that for a range
     * of days must not become a query per day. These identifiers come from
     * roster rows, never from a browser, so there is no session to check here —
     * every screen that reaches this point resolved ownership first.
     *
     * @param list<string> $assignmentIds
     *
     * @return array<string, DateTimeZone> keyed by assignment id
     */
    public function timeZonesFor(array $assignmentIds): array;
}
