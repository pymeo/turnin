<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class ParsedScheduleView
{
    /** @param list<array{type: string, presetId: string|null, abbreviation: string, label: string}> $patternSlots */
    public function __construct(
        public ScheduleDraftPreview $preview,
        public array $patternSlots,
        public string $patternSequence,
        public bool $understoodNothing,
    ) {
    }
}
