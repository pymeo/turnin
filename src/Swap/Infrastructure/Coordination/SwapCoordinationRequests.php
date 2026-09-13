<?php

declare(strict_types=1);

namespace App\Swap\Infrastructure\Coordination;

use App\Coordination\Application\Command\CoordinationSwapRequests;
use App\Swap\Application\Command\OpenSwapRequest;
use App\Swap\Application\Command\OpenSwapRequestHandler;

final readonly class SwapCoordinationRequests implements CoordinationSwapRequests
{
    public function __construct(private OpenSwapRequestHandler $open)
    {
    }

    public function open(string $workerId, string $workerAssignmentId, string $date): string
    {
        return ($this->open)(new OpenSwapRequest($workerId, $workerAssignmentId, $date));
    }
}
