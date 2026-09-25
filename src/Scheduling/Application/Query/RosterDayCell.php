<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

/**
 * One square of the month grid, already rendered into the handful of strings
 * the template needs. The view does no domain reasoning: a Twig file deciding
 * whether an empty cell means "off" is how that rule ends up in three places.
 */
final readonly class RosterDayCell
{
    /** @param list<string> $segments
     * @param list<RosterShiftSegmentView> $shiftSegments
     * @param list<RosterSwapTrace>        $swapTraces
     */
    public function __construct(
        public string $date,
        public int $dayNumber,
        public bool $inMonth,
        public bool $isToday,
        public string $state,
        public string $abbreviation,
        public string $tone,
        public string $label,
        public array $segments,
        public string $ariaLabel,
        public bool $isWeekend,
        /** Both the received shift and the newly freed day keep this trace. */
        public bool $fromSwap = false,
        public array $shiftSegments = [],
        public array $swapTraces = [],
    ) {
    }

    public function isUnknown(): bool
    {
        return 'unknown' === $this->state;
    }
}
