<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * One shift on the composer screen: a cell in the calendar, a row in the list
 * and, for the shift being taken, the summary at the top.
 *
 * A shift that cannot be offered still gets one of these. Hiding it would take
 * away the one thing the screen exists to give — whether that day is worked —
 * so it travels with the reason it is unavailable instead.
 */
final readonly class SwapComposerShiftView
{
    public function __construct(
        /** assignmentId|date, the pair CreateSwapProposal takes */
        public string $key,
        public string $assignmentId,
        public string $date,
        public string $dateHeadline,
        public string $dateCompact,
        public string $shiftLabel,
        public string $abbreviation,
        public string $hours,
        public int $durationMinutes,
        public string $durationLabel,
        public bool $endsNextDay,
        public string $shiftKind,
        public string $tone,
        public string $groupLabel,
        public string $workplaceName,
        public bool $selectable,
        public ?string $blockedReason,
        public int $balanceMinutes,
        public string $balanceLabel,
        public string $balanceHint,
        public ?SwapComposerOpportunityView $opportunity,
        public int $score,
    ) {
    }
}
