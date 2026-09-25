<?php

declare(strict_types=1);

namespace App\Swap\Application;

final readonly class SwapNotificationOutcome
{
    public function __construct(
        public string $proposalId,
        public string $requestOwnerId,
        public string $proposerId,
        public string $requestedDate,
        public bool $requiresApproval = false,
        public int $optionCount = 0,
        public string $swapPoolId = '',
    ) {
    }
}
