<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

use App\Scheduling\Domain\ScheduleDraftEntry;

final readonly class CalendarImportItem
{
    public function __construct(public string $externalEventId, public ScheduleDraftEntry $entry, public string $status, public string $title)
    {
    }
}
