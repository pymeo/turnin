<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class UpcomingShiftView
{
    public function __construct(
        public string $assignmentId,
        public string $swapPoolId,
        public string $date,
        public string $dateHeadline,
        public string $shiftLabel,
        public string $hours,
        public string $shiftKind,
        public string $workplaceName,
        public string $destinationLabel,
        public bool $alreadyOpen,
        public ?string $requestId,
    ) {
    }
}
