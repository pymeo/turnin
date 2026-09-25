<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Swap;

use App\Notification\Domain\PendingApprovals;
use App\Swap\Domain\SwapProposals;

final readonly class SwapPendingApprovals implements PendingApprovals
{
    public function __construct(private SwapProposals $proposals)
    {
    }

    public function countInPool(string $swapPoolId): int
    {
        return $this->proposals->countAwaitingApprovalByPool([$swapPoolId])[$swapPoolId] ?? 0;
    }
}
