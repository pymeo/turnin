<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

final readonly class CoverSwapRequest
{
    public function __construct(public string $ownerId, public string $requestId, public string $availabilityId)
    {
    }
}
