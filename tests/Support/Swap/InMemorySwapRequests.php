<?php

declare(strict_types=1);

namespace App\Tests\Support\Swap;

use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDate;

final class InMemorySwapRequests implements SwapRequests
{
    /** @var array<string, SwapRequest> */
    private array $requests = [];

    /** @param list<SwapRequest> $seed */
    public function __construct(array $seed = [])
    {
        foreach ($seed as $request) {
            $this->save($request);
        }
    }

    public function save(SwapRequest $request): void
    {
        $this->requests[$request->id()] = $request;
    }

    public function byId(string $id): ?SwapRequest
    {
        return $this->requests[$id] ?? null;
    }

    public function openFor(string $workerAssignmentId, WorkDate $date): ?SwapRequest
    {
        foreach ($this->requests as $request) {
            if ($request->isOpen() && $request->workerAssignmentId() === $workerAssignmentId && $request->workDate()->equals($date)) {
                return $request;
            }
        }

        return null;
    }

    public function openInPools(array $swapPoolIds, WorkDate $from, string $excludingWorkerId): array
    {
        $found = [];
        foreach ($this->requests as $request) {
            if ($request->isOpen()
                && \in_array($request->swapPoolId(), $swapPoolIds, true)
                && $request->workDate()->isAfter($from)
                && $request->workerId() !== $excludingWorkerId) {
                $found[] = $request;
            }
        }

        return $found;
    }

    public function openByWorker(string $workerId, WorkDate $from): array
    {
        $found = [];
        foreach ($this->requests as $request) {
            if ($request->isOpen() && $request->workerId() === $workerId && $request->workDate()->isAfter($from)) {
                $found[] = $request;
            }
        }

        return $found;
    }

    public function openDatesFor(string $workerAssignmentId, WorkDate $from, WorkDate $to): array
    {
        $dates = [];
        foreach ($this->requests as $request) {
            $date = (string) $request->workDate();
            if ($request->isOpen() && $request->workerAssignmentId() === $workerAssignmentId && $date >= (string) $from && $date <= (string) $to) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    public function candidateCounts(array $requestIds): array
    {
        return array_fill_keys($requestIds, 0);
    }

    public function count(): int
    {
        return \count($this->requests);
    }
}
