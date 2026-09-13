<?php

declare(strict_types=1);

namespace App\Swap\Domain;

enum SwapProposalStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case WITHDRAWN = 'withdrawn';
    case EXPIRED = 'expired';
}
