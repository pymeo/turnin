<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * A proposal as a row on "Mis intercambios": who, which shift, and what still
 * has to happen. No balances, no minutes, no vocabulary from the model.
 */
final readonly class SwapProposalView
{
    public function __construct(
        public string $proposalId,
        public string $requestId,
        public string $status,
        public string $statusLabel,
        public bool $incoming,
        public bool $decidable,
        public string $otherName,
        public string $requestedHeadline,
        public string $requestedHours,
        public string $requestedDuration,
        public string $requestedLabel,
        public int $optionCount,
        public ?string $chosenHeadline,
        public ?string $chosenHours,
    ) {
    }
}
