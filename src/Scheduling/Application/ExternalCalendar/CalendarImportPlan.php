<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ScheduleDraft;

final readonly class CalendarImportPlan
{
    /** @param list<CalendarImportItem> $items */
    public function __construct(public string $workerAssignmentId, public array $items)
    {
    }

    /** @param list<string>|null $selectedEventIds */
    public function draft(?array $selectedEventIds = null): ScheduleDraft
    {
        $selected = null === $selectedEventIds ? null : array_fill_keys($selectedEventIds, true);
        $entries = [];
        foreach ($this->items as $item) {
            if ('recognized' === $item->status && (null === $selected || isset($selected[$item->externalEventId]))) {
                $entries[] = $item->entry;
            }
        }

        return ScheduleDraft::of($entries, RosterSource::GOOGLE_CALENDAR, $this->workerAssignmentId);
    }
}
