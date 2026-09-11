<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\SwapGroup;

final readonly class GetSwapGroupsHandler
{
    public function __construct(private SwapWorkspace $workspace)
    {
    }

    /** @return list<SwapGroupView> */
    public function __invoke(GetSwapGroups $query): array
    {
        return array_map(static fn (SwapGroup $group): SwapGroupView => new SwapGroupView(
            $group->poolId,
            $group->assignmentId,
            $group->label(),
            $group->fullLabel(),
            $group->workplaceName,
            $group->primary,
        ), $this->workspace->groupsFor($query->workerId));
    }
}
