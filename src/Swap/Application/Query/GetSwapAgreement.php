<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class GetSwapAgreement
{
    public function __construct(public string $workerId, public string $proposalId)
    {
    }
}
