<?php

declare(strict_types=1);

namespace App\Swap\Application;

use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapGroups;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDate;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * Where "you may only see and touch your own groups" is written once.
 *
 * Every identifier that arrives from a browser — a pool, a request, an
 * availability — passes through here and is re-resolved against the session
 * before anything reads or writes. Ten handlers each doing their own check is
 * ten chances for one of them to trust the client instead.
 */
final readonly class SwapWorkspace
{
    public function __construct(
        private SwapGroups $groups,
        private SwapRequests $requests,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<SwapGroup> */
    public function groupsFor(string $workerId): array
    {
        return $this->groups->activeFor($workerId);
    }

    /** @return non-empty-list<SwapGroup> */
    public function requireGroups(string $workerId): array
    {
        $groups = $this->groups->activeFor($workerId);
        if ([] === $groups) {
            throw SwapAccessDenied::noAssignment();
        }

        return $groups;
    }

    /** @return list<SwapGroup> The groups a shift on that calendar can be offered to. */
    public function groupsForAssignment(string $workerId, string $assignmentId): array
    {
        return $this->groups->forAssignment($workerId, $assignmentId);
    }

    public function requireGroup(string $workerId, string $swapPoolId): SwapGroup
    {
        return $this->groups->membership($workerId, $swapPoolId) ?? throw SwapAccessDenied::notAMember();
    }

    /**
     * A pool is only usable from an assignment that actually reaches it, so a
     * shift from one centre cannot be published to another centre's group.
     */
    public function requireGroupOfAssignment(string $workerId, string $assignmentId, string $swapPoolId): SwapGroup
    {
        foreach ($this->groups->forAssignment($workerId, $assignmentId) as $group) {
            if ($group->poolId === $swapPoolId) {
                return $group;
            }
        }

        throw SwapAccessDenied::notAMember();
    }

    public function requireOwnRequest(string $workerId, string $requestId): SwapRequest
    {
        $request = $this->requests->byId($requestId);
        if (null === $request || $request->workerId() !== $workerId) {
            throw SwapAccessDenied::notYours();
        }

        return $request;
    }

    /**
     * A request is readable by the people who could act on it: its author, and
     * anyone with an active membership in the pool it was published to.
     */
    public function requireVisibleRequest(string $workerId, string $requestId): SwapRequest
    {
        $request = $this->requests->byId($requestId);
        if (null === $request) {
            throw SwapAccessDenied::notYours();
        }
        if ($request->workerId() !== $workerId) {
            $this->requireGroup($workerId, $request->swapPoolId());
        }

        return $request;
    }

    /**
     * Today where the worker works. Canarias is an hour behind the peninsula,
     * and "is this shift still in the future?" has a different answer there for
     * an hour every night.
     */
    public function today(SwapGroup $group): WorkDate
    {
        return WorkDate::fromString($this->clock->now()->setTimezone(new DateTimeZone($group->timeZoneId))->format('Y-m-d'));
    }
}
