<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * One day of the worker's own calendar.
 *
 * A rest day is not an empty cell: "libre" and "no lo he rellenado" are
 * different answers, and reading a block of days off is the whole reason this
 * screen is a calendar instead of a list.
 */
final readonly class SwapComposerDayView
{
    /** @param list<SwapComposerShiftView> $shifts */
    public function __construct(
        public string $date,
        public int $dayNumber,
        public string $weekday,
        public string $dateCompact,
        public bool $isToday,
        public bool $isPast,
        public bool $isRestDay,
        public bool $isUnknown,
        public string $stateLabel,
        public array $shifts,
        public bool $isSelectable,
        /** Set only when the day has exactly one offerable shift. */
        public ?string $selectableKey,
        public ?SwapComposerOpportunityView $opportunity,
        public string $ariaLabel,
    ) {
    }
}
