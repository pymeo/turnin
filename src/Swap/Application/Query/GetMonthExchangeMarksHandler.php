<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\Availabilities;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDate;
use InvalidArgumentException;

/**
 * Which cells of a month carry a badge. Two range queries for the whole grid,
 * because a calendar must not pay a query per day to say "this one is
 * published".
 */
final readonly class GetMonthExchangeMarksHandler
{
    public function __construct(
        private SwapWorkspace $workspace,
        private SwapRequests $requests,
        private Availabilities $availabilities,
    ) {
    }

    public function __invoke(GetMonthExchangeMarks $query): MonthExchangeMarksView
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})$/D', $query->month, $parts)) {
            throw new InvalidArgumentException('Un mes se escribe como YYYY-MM.');
        }

        // Reading marks requires owning the calendar, same as reading the month.
        $groups = $this->workspace->groupsForAssignment($query->workerId, $query->workerAssignmentId);
        if ([] === $groups) {
            return new MonthExchangeMarksView([], []);
        }

        $year = (int) $parts[1];
        $month = (int) $parts[2];
        $from = WorkDate::fromString(\sprintf('%04d-%02d-01', $year, $month));
        $to = WorkDate::fromString(\sprintf('%04d-%02d-%02d', $year, $month, self::lastDay($year, $month)));

        return new MonthExchangeMarksView(
            $this->requests->openDatesFor($query->workerAssignmentId, $from, $to),
            $this->availabilities->activeDatesFor($query->workerId, $from, $to),
        );
    }

    private static function lastDay(int $year, int $month): int
    {
        if (2 === $month) {
            return 0 === $year % 4 && (0 !== $year % 100 || 0 === $year % 400) ? 29 : 28;
        }

        return \in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }
}
