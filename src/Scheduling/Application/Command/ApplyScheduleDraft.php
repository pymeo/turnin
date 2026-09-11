<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Domain\ConflictPolicy;
use App\Scheduling\Domain\DraftInstruction;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ScheduleDraft;

/**
 * The one way anything is written to a roster. Painting, rotations, dictation
 * and — later — an approved swap all end up here.
 */
final readonly class ApplyScheduleDraft
{
    /** @param list<DraftInstruction> $instructions */
    public function __construct(
        public string $workerId,
        public array $instructions,
        public RosterSource $source,
        public ConflictPolicy $policy = ConflictPolicy::SKIP_EXISTING,
        public ?string $assignmentId = null,
        public ?ScheduleDraft $preparedDraft = null,
    ) {
    }
}
