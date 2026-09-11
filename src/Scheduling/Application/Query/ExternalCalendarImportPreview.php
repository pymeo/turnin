<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Application\ExternalCalendar\CalendarImportItem;

final readonly class ExternalCalendarImportPreview
{
    /** @param list<CalendarImportItem> $items */
    public function __construct(public ScheduleDraftPreview $draft, public array $items, public int $recognized, public int $review, public int $ignored)
    {
    }
}
