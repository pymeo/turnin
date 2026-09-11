<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * What a worker does on a day they have told us about.
 *
 * There is deliberately no UNKNOWN case: not knowing is the *absence* of a
 * RosterDay, not a third state stored in a column. "I have not filled this in"
 * and "I am off" are different answers, and a calendar that conflates them
 * would offer someone's rest day to a stranger.
 */
enum RosterDayState: string
{
    case REST = 'rest';
    case WORKING = 'working';
}
