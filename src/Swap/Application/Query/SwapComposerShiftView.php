<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

/**
 * One of my shifts on the composer screen, already judged.
 *
 * `selectable` is not "is it mine and in the future": it is the answer to
 * whether the colleague who published the request can actually work it, decided
 * against real shift intervals. A shift they cannot do stays on the calendar
 * with the reason, because taking it away would take away the context the
 * calendar exists to give.
 */
final readonly class SwapComposerShiftView
{
    public function __construct(
        /** assignmentId|date, the pair the form posts back */
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
        /** A quiet nudge, never a decision: "Te dejaría 4 días seguidos libres". */
        public ?string $recommendation,
        /** Helpful context that never prevents selecting the shift. */
        public ?string $compatibilityNote = null,
    ) {
    }
}
