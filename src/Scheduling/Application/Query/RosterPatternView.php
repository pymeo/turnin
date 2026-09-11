<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class RosterPatternView
{
    /** @param list<array{type: string, presetId: string|null, abbreviation: string, label: string}> $slots */
    public function __construct(public string $id, public string $name, public int $length, public string $sequence, public array $slots)
    {
    }
}
