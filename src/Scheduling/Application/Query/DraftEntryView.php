<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class DraftEntryView
{
    /** @param list<string> $presetIds */
    public function __construct(
        public string $date,
        public int $dayNumber,
        public string $intent,
        public string $abbreviation,
        public string $tone,
        public string $description,
        public array $presetIds,
        public bool $conflicting,
        public ?string $existing,
        public bool $applies,
    ) {
    }
}
