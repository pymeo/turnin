<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * Instructions from the interface → a draft, with every preset resolved against
 * the worker's own catalogue. A preset id that is not theirs simply does not
 * resolve: it becomes an unresolved entry, visible in the preview and never
 * written.
 */
final readonly class ScheduleDraftComposer
{
    /** @param list<DraftInstruction> $instructions */
    public function compose(array $instructions, ShiftPresetResolver $presets, RosterSource $source, ?string $workerAssignmentId = null): ScheduleDraft
    {
        $entries = [];

        foreach ($instructions as $instruction) {
            if (DraftIntent::REST === $instruction->intent) {
                $entries[] = ScheduleDraftEntry::rest($instruction->date);
                continue;
            }
            if (DraftIntent::CLEAR === $instruction->intent) {
                $entries[] = ScheduleDraftEntry::clear($instruction->date);
                continue;
            }

            $segments = [];
            foreach ($instruction->presetIds as $presetId) {
                $preset = $presets->byId($presetId);
                if (null !== $preset) {
                    $segments[] = SegmentProposal::fromPreset($preset);
                }
            }

            $entries[] = [] === $segments
                ? ScheduleDraftEntry::unresolved($instruction->date, \sprintf('El turno indicado para el %s ya no está disponible.', $instruction->date))
                : ScheduleDraftEntry::work($instruction->date, $segments);
        }

        return ScheduleDraft::of($entries, $source, $workerAssignmentId);
    }
}
