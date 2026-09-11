<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class MySwapRequestView
{
    public function __construct(
        public string $requestId,
        public string $date,
        public string $dateHeadline,
        public string $shiftLabel,
        public string $abbreviation,
        public string $hours,
        public string $colorKey,
        public string $workplaceName,
        public string $groupLabel,
        public int $candidateCount,
    ) {
    }
}
