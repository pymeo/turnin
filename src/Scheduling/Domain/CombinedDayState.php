<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

enum CombinedDayState: string
{
    case GLOBAL_FREE = 'global_free';
    case WORKING = 'working';
    case PARTIALLY_KNOWN = 'partially_known';
    case UNKNOWN = 'unknown';
    case OVERLAPPING = 'overlapping';
}
