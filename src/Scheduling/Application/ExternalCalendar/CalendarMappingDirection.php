<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

enum CalendarMappingDirection: string
{
    case IMPORT = 'import';
    case EXPORT = 'export';
}
