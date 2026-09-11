<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class RosterMonthView
{
    /**
     * @param list<RosterDayCell>       $cells    Always six weeks, Monday first
     * @param list<CalendarInsightView> $insights
     */
    public function __construct(
        public string $month,
        public string $title,
        public string $previousMonth,
        public string $nextMonth,
        public string $today,
        public array $cells,
        public RosterSummaryView $summary,
        public array $insights,
        public bool $hasAnyRoster,
    ) {
    }
}
