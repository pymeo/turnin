<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * The extension point matching will plug into. One implementation today, which
 * reads streaks straight off the month; the interface exists because the swap
 * engine is the second, not because interfaces are tidy.
 */
interface CalendarInsightSource
{
    /**
     * @param list<RosterDay> $days
     *
     * @return list<CalendarInsight>
     */
    public function insightsFor(RosterMonth $month, array $days): array;
}
