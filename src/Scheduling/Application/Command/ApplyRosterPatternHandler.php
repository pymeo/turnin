<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Application\RosterPatternExpansion;
use App\Scheduling\Domain\DraftInstruction;
use App\Scheduling\Domain\DraftIntent;
use App\Scheduling\Domain\SegmentProposal;

/**
 * Rolls the rotation out over the range and hands the result to the one writer.
 * It persists nothing itself: a second write path would mean a second place to
 * get conflicts, ownership and transactions right, which is exactly what this
 * slice refuses to grow.
 */
final readonly class ApplyRosterPatternHandler
{
    public function __construct(private RosterPatternExpansion $expansion, private ApplyScheduleDraftHandler $writer)
    {
    }

    public function __invoke(ApplyRosterPattern $command): ScheduleDraftApplied
    {
        $expanded = $this->expansion->expand($command->workerId, $command->patternId, $command->from, $command->to);

        $instructions = [];
        foreach ($expanded->draft->entries as $entry) {
            if (!$entry->isApplicable()) {
                continue;
            }
            $instructions[] = new DraftInstruction($entry->date, $entry->intent, $this->presetIdsOf($entry->intent, $entry->segments));
        }

        return ($this->writer)(new ApplyScheduleDraft($command->workerId, $instructions, $expanded->draft->source, $command->policy));
    }

    /**
     * @param list<SegmentProposal> $segments
     *
     * @return list<string>
     */
    private function presetIdsOf(DraftIntent $intent, array $segments): array
    {
        if (DraftIntent::WORK !== $intent) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (SegmentProposal $segment): ?string => $segment->presetId, $segments)));
    }
}
