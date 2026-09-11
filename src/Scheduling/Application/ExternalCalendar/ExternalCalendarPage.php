<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

final readonly class ExternalCalendarPage
{
    /** @param list<ExternalCalendarEvent> $events */
    public function __construct(public array $events, public ?string $nextSyncToken)
    {
    }
}
