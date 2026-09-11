<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Domain\ConflictPolicy;
use App\Scheduling\Domain\DraftInstruction;
use App\Scheduling\Domain\RosterSource;

final readonly class PreviewScheduleDraft
{
    /** @param list<DraftInstruction> $instructions */
    public function __construct(
        public string $workerId,
        public array $instructions,
        public RosterSource $source,
        public ConflictPolicy $policy = ConflictPolicy::SKIP_EXISTING,
        public ?string $assignmentId = null,
    ) {
    }
}
