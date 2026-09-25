<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * How many agreements of a pool are waiting for a supervisor. Implemented from
 * Swap's data; Notification only needs the number to decide what to say.
 */
interface PendingApprovals
{
    public function countInPool(string $swapPoolId): int;
}
