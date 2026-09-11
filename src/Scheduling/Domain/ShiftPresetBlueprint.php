<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

final readonly class ShiftPresetBlueprint
{
    /** @param list<string> $aliases */
    public function __construct(
        public string $name,
        public string $abbreviation,
        public ShiftWindow $window,
        public ShiftKind $kind,
        public array $aliases,
        public ?ShiftColor $color = null,
    ) {
    }
}
