<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class CombinedShiftView
{
    public function __construct(public string $assignmentId, public string $workplace, public string $destination, public string $label, public string $abbreviation, public string $start, public string $end, public string $colorKey, public bool $startsPreviousDay = false)
    {
    }
}
