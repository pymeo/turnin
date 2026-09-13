<?php

declare(strict_types=1);

namespace App\Swap\Domain;

enum ExchangeBalanceStatus: string
{
    case OPEN = 'open';
    case PARTIALLY_REDEEMED = 'partially_redeemed';
    case FULFILLED = 'fulfilled';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
}
