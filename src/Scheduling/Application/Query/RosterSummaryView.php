<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class RosterSummaryView
{
    public function __construct(
        public int $workedDays,
        public int $restDays,
        public int $nightShifts,
        public int $unknownDays,
        public int $longestRestStreak,
        public bool $isEmpty,
    ) {
    }
}
