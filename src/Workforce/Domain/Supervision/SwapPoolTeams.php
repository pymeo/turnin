<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

interface SwapPoolTeams
{
    /** Always a team, possibly empty: an unknown or inactive pool has no members. */
    public function team(string $swapPoolId): SwapPoolTeam;

    public function describe(string $swapPoolId): ?SwapPoolDescription;

    /** @return list<string> pools where the worker has an active membership, primary first */
    public function poolsOf(string $workerId): array;
}
