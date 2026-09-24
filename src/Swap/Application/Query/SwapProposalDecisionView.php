<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * "Pedro te hace el sábado. ¿Cuál de estos le puedes hacer tú?".
 *
 * The whole answering screen: what they take on, what they offer back, and
 * which of those the person reading it can actually do right now.
 */
final readonly class SwapProposalDecisionView
{
    /** @param list<SwapProposalOptionView> $options */
    public function __construct(
        public string $proposalId,
        public string $otherName,
        public bool $incoming,
        public string $status,
        public bool $decidable,
        public string $requestedHeadline,
        public string $requestedHours,
        public string $requestedDuration,
        public string $requestedLabel,
        public string $requestedGroupLabel,
        public array $options,
        public ?string $chosenOptionId,
        public bool $requiresApproval,
    ) {
    }
}
