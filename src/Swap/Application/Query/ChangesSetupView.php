<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class ChangesSetupView
{
    /**
     * @param list<UpcomingShiftView> $upcomingShifts
     * @param list<string>            $workingDates
     */
    public function __construct(
        public array $upcomingShifts,
        public array $workingDates,
        public string $calendarFrom,
        public string $calendarTo,
    ) {
    }
}
