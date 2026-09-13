<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

final readonly class ReviewSwapApproval
{
    public function __construct(public string $supervisorUserId, public string $proposalId, public string $decision)
    {
    }
}
