<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

final readonly class DecideSwapProposal
{
    public function __construct(public string $workerId, public string $proposalId, public string $decision)
    {
    }
}
