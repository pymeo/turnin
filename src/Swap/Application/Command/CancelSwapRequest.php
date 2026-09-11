<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

final readonly class CancelSwapRequest
{
    public function __construct(public string $workerId, public string $requestId)
    {
    }
}
