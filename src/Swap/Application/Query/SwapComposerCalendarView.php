<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * Everything the "which of my shifts do I want covered" screen renders, resolved
 * in one query.
 *
 * It carries the colleague's given name and the single shift they published and
 * nothing else about them: this is the worker's own calendar, and the other
 * person's rota never appears on it.
 */
final readonly class SwapComposerCalendarView
{
    /**
     * @param list<SwapComposerWeekView>           $weeks
     * @param list<SwapComposerShiftView>          $shifts          every shift in the window, in date order
     * @param list<SwapComposerRecommendationView> $recommendations at most two, best first
     */
    public function __construct(
        public string $requestId,
        public string $authorName,
        public SwapComposerShiftView $requestedShift,
        public string $rangeStart,
        public string $rangeEnd,
        public string $rangeLabel,
        public int $weekOffset,
        public bool $hasPreviousWeeks,
        public bool $hasMoreWeeks,
        public array $weeks,
        public array $shifts,
        public array $recommendations,
        public ?SwapComposerShiftView $selectedShift,
        /** Why no proposal can be made at all, when that is the case. */
        public ?string $blockedReason,
    ) {
    }
}
