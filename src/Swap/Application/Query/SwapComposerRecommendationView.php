<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * The answer to "which one should I pick", with the days that justify it.
 *
 * The timeline runs from the day before the resulting rest block to the day
 * after it, so the screen can show the same stretch of calendar before and
 * after the exchange instead of describing it in a paragraph.
 */
final readonly class SwapComposerRecommendationView
{
    /** @param list<SwapComposerDayView> $timeline */
    public function __construct(
        public SwapComposerShiftView $shift,
        public SwapComposerOpportunityView $opportunity,
        public string $headline,
        public array $timeline,
    ) {
    }
}
