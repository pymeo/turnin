<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * A draft measured against what the calendar already holds. This is what the
 * preview screen renders and what the writer consumes, so the numbers the
 * worker confirmed are the numbers that get written — not a second computation
 * that might disagree.
 */
final readonly class ResolvedScheduleDraft
{
    /**
     * @param list<ResolvedDraftEntry> $entries
     * @param list<string>             $unrecognized
     */
    public function __construct(public array $entries, public ConflictPolicy $policy, public array $unrecognized)
    {
    }

    /** @return list<ResolvedDraftEntry> */
    public function conflicts(): array
    {
        return array_values(array_filter($this->entries, static fn (ResolvedDraftEntry $entry): bool => $entry->conflicting));
    }

    /** @return list<ScheduleDraftEntry> */
    public function entriesToWrite(): array
    {
        return array_values(array_map(
            static fn (ResolvedDraftEntry $entry): ScheduleDraftEntry => $entry->entry,
            array_filter($this->entries, static fn (ResolvedDraftEntry $entry): bool => $entry->applies),
        ));
    }

    public function affectedDays(): int
    {
        return \count($this->entriesToWrite());
    }

    public function conflictCount(): int
    {
        return \count($this->conflicts());
    }

    public function shiftCount(): int
    {
        return \count(array_filter($this->entries, static fn (ResolvedDraftEntry $entry): bool => DraftIntent::WORK === $entry->entry->intent && [] !== $entry->entry->segments));
    }

    public function restCount(): int
    {
        return \count(array_filter($this->entries, static fn (ResolvedDraftEntry $entry): bool => DraftIntent::REST === $entry->entry->intent));
    }

    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }
}
