<?php

declare(strict_types=1);

namespace App\Swap\Domain;

/**
 * What Scheduling says about a day, as far as Swap needs to care.
 *
 * UNKNOWN is a real answer and not a missing one: it is the difference between
 * "I am off" and "I have not filled that in", and offering somebody's shift or
 * their rest day on the strength of a blank cell is the failure this whole
 * design is arranged to prevent.
 */
enum RosteredDayState: string
{
    case WORKING = 'working';
    case REST = 'rest';
    case UNKNOWN = 'unknown';
}
