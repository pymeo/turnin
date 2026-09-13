<?php

declare(strict_types=1);

namespace App\Swap\Domain;

/**
 * Every read is scoped by pool or by worker. There is deliberately no "all open
 * requests": the only legitimate audiences for a request are the groups its
 * author belongs to, and an API that makes the unscoped query easy is the one
 * somebody will call.
 */
interface SwapRequests
{
    public function save(SwapRequest $request): void;

    public function byId(string $id): ?SwapRequest;

    /** Must be called inside {@see SwapTransaction}; serializes competing decisions. */
    public function byIdForUpdate(string $id): ?SwapRequest;

    public function openFor(string $workerAssignmentId, WorkDate $date): ?SwapRequest;

    /**
     * Open requests in the given pools, from the given date on, excluding the
     * worker doing the asking — you never need to be offered your own shift.
     *
     * @param list<string> $swapPoolIds
     *
     * @return list<SwapRequest>
     */
    public function openInPools(array $swapPoolIds, WorkDate $from, string $excludingWorkerId): array;

    /** @return list<SwapRequest> Open requests the worker published, soonest first. */
    public function openByWorker(string $workerId, WorkDate $from): array;

    /**
     * The days of one calendar that currently carry an open request, so a month
     * can be badged with one query instead of one per cell.
     *
     * @return list<string> dates as YYYY-MM-DD
     */
    public function openDatesFor(string $workerAssignmentId, WorkDate $from, WorkDate $to): array;

    /**
     * How many people have offered for each request, in one query.
     *
     * @param list<string> $requestIds
     *
     * @return array<string, int>
     */
    public function candidateCounts(array $requestIds): array;
}
