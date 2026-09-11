<?php

declare(strict_types=1);

namespace App\Swap\Domain;

/**
 * Two states, and that is the whole machine for now.
 *
 * docs/DOMAIN.md sketches a longer life cycle — proposed, accepted, approved —
 * but none of those transitions has anything that could cause it yet: there are
 * no proposals and no agreements. A `CLOSED` case would be a state nothing can
 * reach, which is worse than a missing one: it invites code to handle a
 * situation that cannot happen and hides the fact that the flow is unfinished.
 */
enum SwapRequestStatus: string
{
    case OPEN = 'open';
    case CANCELLED = 'cancelled';
}
