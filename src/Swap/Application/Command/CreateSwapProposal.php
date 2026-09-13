<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

final readonly class CreateSwapProposal
{
    public function __construct(
        public string $workerId,
        public string $requestId,
        public string $kind,
        public ?string $offeredAssignmentId = null,
        public ?string $offeredDate = null,
        public ?string $preferredMonth = null,
        public ?string $preferredShiftKind = null,
        public ?int $preferredDurationMinutes = null,
        /** @var list<int> */
        public array $preferredWeekdays = [],
    ) {
    }
}
