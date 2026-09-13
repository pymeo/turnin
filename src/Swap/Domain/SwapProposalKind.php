<?php

declare(strict_types=1);

namespace App\Swap\Domain;

enum SwapProposalKind: string
{
    case EXCHANGE = 'exchange';
    case DEFERRED = 'deferred';
    case COVERAGE = 'coverage';
    case REDEMPTION = 'redemption';
}
