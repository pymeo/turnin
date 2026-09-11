<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class DetectedPatternView
{
    /** @param list<array{type: string, presetId: string|null, abbreviation: string, label: string}> $slots */
    public function __construct(public int $length, public string $sequence, public string $repeatsFrom, public int $observedCycles, public array $slots)
    {
    }
}
