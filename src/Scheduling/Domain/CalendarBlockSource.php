<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

enum CalendarBlockSource: string
{
    case MANUAL = 'manual';
    case GOOGLE_CALENDAR = 'google_calendar';
}
