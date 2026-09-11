<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class ShiftPresetView
{
    /** @param list<string> $aliases */
    public function __construct(
        public string $id,
        public string $name,
        public string $abbreviation,
        public string $start,
        public string $end,
        public string $kind,
        public string $tone,
        public bool $endsNextDay,
        public int $position,
        public bool $active,
        public array $aliases,
    ) {
    }

    public function hours(): string
    {
        return \sprintf('%s – %s', $this->start, $this->end);
    }
}
