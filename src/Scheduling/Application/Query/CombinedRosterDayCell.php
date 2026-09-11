<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class CombinedRosterDayCell
{
    /** @param list<CombinedShiftView> $shifts */
    public function __construct(public string $date, public int $dayNumber, public bool $inMonth, public bool $isToday, public bool $isWeekend, public string $state, public array $shifts, public bool $overlap, public string $ariaLabel)
    {
    }
}
