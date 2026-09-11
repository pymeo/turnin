<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class GetOpenSwapRequests
{
    /** @param string|null $swapPoolId narrows to one group; null means all of mine */
    public function __construct(public string $workerId, public ?string $swapPoolId = null)
    {
    }
}
