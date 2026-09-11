<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * Something true about the worker's own calendar, phrased for a human.
 *
 * Strictly what the calendar already says — "tienes 4 días libres seguidos del
 * 16 al 19". Not "con un cambio podrías librar el puente": that needs a
 * matching engine, and inventing the answer before the engine exists would
 * teach people to ignore the panel by the time it becomes true.
 */
final readonly class CalendarInsight
{
    public function __construct(public string $headline, public string $detail)
    {
    }
}
