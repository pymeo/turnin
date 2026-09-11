<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

/**
 * Withdraws by identity when the worker taps a row, or by day when they tap
 * "retirar disponibilidad" on a calendar cell that may cover several groups.
 */
final readonly class WithdrawAvailability
{
    /** @param list<string> $swapPoolIds empty, with a date, means every group */
    public function __construct(
        public string $workerId,
        public ?string $availabilityId = null,
        public ?string $date = null,
        public array $swapPoolIds = [],
    ) {
    }
}
