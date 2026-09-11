<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * What a draft entry proposes for one day.
 *
 * CLEAR is not "rest": it removes the day entirely and returns it to UNKNOWN.
 * "I am off that day" and "forget what I told you" are different statements and
 * the interface offers them as different buttons.
 */
enum DraftIntent: string
{
    case WORK = 'work';
    case REST = 'rest';
    case CLEAR = 'clear';
}
