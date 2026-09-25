<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

/** "Ask my team to confirm that I am their supervisor." */
final readonly class RequestSupervision
{
    public function __construct(public string $workerId, public string $swapPoolId)
    {
    }
}
