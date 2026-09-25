<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class RosterSwapTraceSegment
{
    public function __construct(public string $start, public string $end, public int $durationMinutes, public string $label, public string $abbreviation, public string $color)
    {
    }
}
