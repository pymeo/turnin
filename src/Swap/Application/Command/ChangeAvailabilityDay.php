<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

final readonly class ChangeAvailabilityDay
{
    /**
     * @param list<string> $swapPoolIds
     * @param list<string> $shiftKinds
     */
    public function __construct(
        public string $workerId,
        public string $date,
        public array $swapPoolIds,
        public array $shiftKinds,
    ) {
    }
}
