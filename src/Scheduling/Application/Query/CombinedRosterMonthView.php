<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class CombinedRosterMonthView
{
    /** @param list<CombinedRosterDayCell> $cells */
    public function __construct(public string $month, public string $title, public string $previousMonth, public string $nextMonth, public string $today, public array $cells, public int $shiftCount, public int $overlapCount)
    {
    }
}
