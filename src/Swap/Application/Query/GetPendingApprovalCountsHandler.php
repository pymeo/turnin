<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Domain\ShiftExchangeGovernance;
use App\Swap\Domain\SwapProposals;

final readonly class GetPendingApprovalCountsHandler
{
    public function __construct(private ShiftExchangeGovernance $governance, private SwapProposals $proposals)
    {
    }

    /** @return array<string, int> */
    public function __invoke(GetPendingApprovalCounts $query): array
    {
        return $this->proposals->countAwaitingApprovalByPool($this->governance->supervisedPools($query->supervisorUserId));
    }
}
