<?php

declare(strict_types=1);

namespace App\Swap\Domain;

enum SwapProposalStatus: string
{
    case PENDING = 'pending';
    /** Kept for rows executed by the first proposal implementation. */
    case ACCEPTED = 'accepted';
    case PENDING_APPROVAL = 'pending_approval';
    case EXECUTED = 'executed';
    case REJECTED = 'rejected';
    case APPROVAL_REJECTED = 'approval_rejected';
    case WITHDRAWN = 'withdrawn';
    case EXPIRED = 'expired';
}
