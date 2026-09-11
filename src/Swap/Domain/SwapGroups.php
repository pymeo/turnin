<?php

declare(strict_types=1);

namespace App\Swap\Domain;

/**
 * Declared by Swap, implemented by Workforce.
 *
 * The frontier of who can see whose shifts is the swap pool, not the hospital:
 * a nurse in intensive care and a porter in A&E share a building and nothing
 * else. Workforce already resolves that key; this context asks for the answer
 * instead of recomputing it. See docs/DOMAIN.md § SwapPool.
 */
interface SwapGroups
{
    /**
     * Every pool the worker has an active membership in, across all their
     * active assignments.
     *
     * @return list<SwapGroup>
     */
    public function activeFor(string $workerId): array;

    /**
     * The pools reachable from one of the worker's assignments — the groups a
     * shift on that calendar can be offered to.
     *
     * @return list<SwapGroup>
     */
    public function forAssignment(string $workerId, string $assignmentId): array;

    /** Null when the worker has no active membership in that pool. */
    public function membership(string $workerId, string $swapPoolId): ?SwapGroup;
}
