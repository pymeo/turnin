<?php

declare(strict_types=1);

namespace App\Scheduling\Application;

use App\Scheduling\Application\Query\DraftEntryView;
use App\Scheduling\Application\Query\ScheduleDraftPreview;
use App\Scheduling\Domain\AssignedWorker;
use App\Scheduling\Domain\ConflictPolicy;
use App\Scheduling\Domain\DraftIntent;
use App\Scheduling\Domain\ResolvedDraftEntry;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\ScheduleDraft;
use App\Scheduling\Domain\ScheduleDraftResolver;
use App\Scheduling\Domain\SegmentProposal;

/**
 * Resolves any draft against the calendar and renders it for the preview
 * screen. Painting, rotations and dictation share it, so all three report
 * conflicts in the same words and count days the same way.
 */
final readonly class ScheduleDraftPreviewFactory
{
    public function __construct(private RosterDays $rosterDays, private ScheduleDraftResolver $resolver)
    {
    }

    /** @param list<string> $unrecognized */
    public function build(AssignedWorker $worker, ScheduleDraft $draft, ConflictPolicy $policy, array $unrecognized = []): ScheduleDraftPreview
    {
        $from = $draft->firstDate();
        $to = $draft->lastDate();
        $existing = null === $from || null === $to ? [] : $this->rosterDays->inRange($worker->assignmentId, $from, $to);
        $resolved = $this->resolver->resolve($draft, $existing, $policy, $unrecognized);

        return new ScheduleDraftPreview(
            array_map(fn (ResolvedDraftEntry $entry): DraftEntryView => $this->entry($entry), $resolved->entries),
            \count($resolved->entries),
            $resolved->shiftCount(),
            $resolved->restCount(),
            $resolved->conflictCount(),
            $resolved->affectedDays(),
            $policy->value,
            [...$unrecognized, ...$this->warningsOf($draft)],
            null === $from ? null : (string) $from,
            null === $to ? null : (string) $to,
        );
    }

    private function entry(ResolvedDraftEntry $resolved): DraftEntryView
    {
        $entry = $resolved->entry;
        $tone = match ($entry->intent) {
            DraftIntent::REST => 'rest',
            DraftIntent::CLEAR => 'unknown',
            DraftIntent::WORK => ($entry->segments[0] ?? null)?->kind->tone() ?? 'oncall',
        };

        return new DraftEntryView(
            (string) $entry->date,
            $entry->date->day,
            $entry->intent->value,
            $entry->abbreviation(),
            $tone,
            $entry->describe(),
            array_values(array_filter(array_map(static fn (SegmentProposal $segment): ?string => $segment->presetId, $entry->segments))),
            $resolved->conflicting,
            $resolved->existingDescription(),
            $resolved->applies,
        );
    }

    /** @return list<string> */
    private function warningsOf(ScheduleDraft $draft): array
    {
        $warnings = [];
        foreach ($draft->unresolvedEntries() as $entry) {
            foreach ($entry->warnings as $warning) {
                $warnings[] = $warning;
            }
        }

        return array_values(array_unique($warnings));
    }
}
