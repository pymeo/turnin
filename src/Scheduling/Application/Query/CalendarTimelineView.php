<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class CalendarTimelineView
{
    /** @param list<CalendarEntryView> $entries */
    public function __construct(public array $entries)
    {
    }
}
