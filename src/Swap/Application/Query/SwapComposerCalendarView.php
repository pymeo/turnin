<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * Everything the "which of my shifts could you do" screen renders, in one read.
 *
 * It carries the colleague's given name and the single shift they published and
 * nothing else about them: this is the worker's own calendar, and the other
 * person's rota never appears on it — only the conclusion about each of *my*
 * shifts, which is what the choice actually depends on.
 */
final readonly class SwapComposerCalendarView
{
    /**
     * @param list<SwapComposerWeekView>  $weeks
     * @param list<SwapComposerShiftView> $shifts       every shift in the window, in date order
     * @param list<string>                $selectedKeys what is ticked right now
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
        public array $selectedKeys,
        public int $maximumOptions,
        /** Why no proposal can be made at all, when that is the case. */
        public ?string $blockedReason,
    ) {
    }
}
