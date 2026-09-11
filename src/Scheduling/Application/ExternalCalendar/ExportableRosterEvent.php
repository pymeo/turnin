<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

final readonly class ExportableRosterEvent
{
    public function __construct(public string $rosterDayId, public string $segmentId, public ExternalCalendarEventDraft $event)
    {
    }
}
