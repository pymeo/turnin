<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

final readonly class ScheduleDraftApplied
{
    public function __construct(
        public int $writtenDays,
        public int $clearedDays,
        public int $skippedConflicts,
        public string $firstDate,
        public string $lastDate,
    ) {
    }
}
