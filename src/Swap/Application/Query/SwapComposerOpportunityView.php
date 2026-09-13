<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * What handing one shift over would do to a rest block, as the calendar shows
 * it. Every number here comes from RestBlockOpportunityFinder; nothing on this
 * screen decides on its own what counts as a bridge.
 */
final readonly class SwapComposerOpportunityView
{
    /** @param list<string> $reasons */
    public function __construct(
        public int $resultingRestDays,
        public int $gainedRestDays,
        public string $restStartsAt,
        public string $restEndsAt,
        public string $restRangeLabel,
        public string $badgeLabel,
        public string $summaryLabel,
        public array $reasons,
        public int $score,
    ) {
    }
}
