<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\SwapRequests;
use Psr\Clock\ClockInterface;

final readonly class CancelSwapRequestHandler
{
    public function __construct(
        private SwapWorkspace $workspace,
        private SwapRequests $requests,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CancelSwapRequest $command): void
    {
        $request = $this->workspace->requireOwnRequest($command->workerId, $command->requestId);
        if (!$request->isOpen()) {
            // Withdrawing twice is withdrawn.
            return;
        }

        $request->cancel($command->workerId, $this->clock->now());
        $this->requests->save($request);
    }
}
