<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class AvailabilityPlaceView
{
    /**
     * @param list<string> $destinations
     * @param list<string> $categories
     * @param list<string> $poolIds
     */
    public function __construct(
        public string $workplaceName,
        public array $destinations,
        public array $categories,
        public array $poolIds,
    ) {
    }
}
