<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class SwapProposalView
{
    public function __construct(
        public string $proposalId,
        public string $kind,
        public string $status,
        public bool $incoming,
        public string $otherName,
        public string $requestedDate,
        public string $requestedHeadline,
        public string $requestedHours,
        public string $requestedDuration,
        public int $requestedMinutes,
        public string $requestedLabel,
        public ?string $offeredDate,
        public ?string $offeredHeadline,
        public ?string $offeredHours,
        public ?string $offeredDuration,
        public ?int $offeredMinutes,
        public ?string $offeredLabel,
        public int $ownerBalanceMinutes,
    ) {
    }
}
