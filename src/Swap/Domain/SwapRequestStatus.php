<?php

declare(strict_types=1);

namespace App\Swap\Domain;

/**
 * A request is covered only after its author chooses one active compatible
 * offer. The conditional persistence transition protects that decision from
 * concurrent acceptance.
 */
enum SwapRequestStatus: string
{
    case OPEN = 'open';
    case CANCELLED = 'cancelled';
    case COVERED = 'covered';
}
