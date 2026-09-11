<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class AvailabilityDayView
{
    /**
     * @param list<string>                $availabilityIds
     * @param list<string>                $shiftKinds
     * @param list<string>                $shiftLabels
     * @param list<AvailabilityPlaceView> $places
     * @param list<string>                $poolIds
     */
    public function __construct(
        public array $availabilityIds,
        public string $date,
        public string $dateHeadline,
        public array $shiftKinds,
        public array $shiftLabels,
        public array $places,
        public array $poolIds,
    ) {
    }
}
