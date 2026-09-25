<?php

declare(strict_types=1);

namespace App\Swap\Domain;

/**
 * Workforce-owned organizational rules projected into Swap.
 *
 * `policyFor` says *what* a pool requires (approval, coverage); the other
 * three say *who* holds that authority. They are separate questions on
 * purpose: a pool can require approval and have nobody verified yet, and its
 * agreements then wait — they are never approved by default.
 */
interface ShiftExchangeGovernance
{
    public function policyFor(string $swapPoolId): ShiftExchangePolicy;

    public function canApprove(string $supervisorUserId, string $swapPoolId): bool;

    /** @return list<string> */
    public function supervisedPools(string $supervisorUserId): array;

    /** @return list<string> verified supervisors of the pool */
    public function approversOf(string $swapPoolId): array;
}
