<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

/**
 * Creates a preset when $presetId is null and reshapes it otherwise. One
 * command because the screen is one form and the validation is identical.
 */
final readonly class SaveShiftPreset
{
    /** @param list<string> $aliases */
    public function __construct(
        public string $workerId,
        public ?string $presetId,
        public string $name,
        public string $abbreviation,
        public string $start,
        public string $end,
        public string $kind,
        public array $aliases = [],
        public string $colorKey = 'slate',
        public ?string $assignmentId = null,
    ) {
    }
}
