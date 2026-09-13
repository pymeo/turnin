<?php

declare(strict_types=1);

namespace App\Swap\Domain;

/** Workforce-owned organizational rules projected into Swap. */
interface ShiftExchangeGovernance
{
    public function policyFor(string $swapPoolId): ShiftExchangePolicy;

    public function canApprove(string $supervisorUserId, string $swapPoolId): bool;

    /** @return list<string> */
    public function supervisedPools(string $supervisorUserId): array;
}
