<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

final readonly class RequestBalanceRedemption
{
    public function __construct(public string $workerId, public string $balanceId, public string $requestId)
    {
    }
}
