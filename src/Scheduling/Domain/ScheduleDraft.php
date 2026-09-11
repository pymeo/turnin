<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * The single shape every way of entering a roster converges on.
 *
 *     painting  ┐
 *     pattern   ├─→ ScheduleDraft ─→ validate ─→ preview ─→ confirm ─→ persist
 *     voice/text┘
 *
 * Three entry points, one model and one writer. Three separate persistence
 * paths would mean three places to get conflicts, authorisation and time zones
 * subtly wrong. A draft never touches the calendar.
 */
final readonly class ScheduleDraft
{
    /** @param list<ScheduleDraftEntry> $entries */
    private function __construct(public array $entries, public RosterSource $source)
    {
    }

    /** @param list<ScheduleDraftEntry> $entries */
    public static function of(array $entries, RosterSource $source): self
    {
        return new self(self::deduplicated($entries), $source);
    }

    public static function empty(RosterSource $source): self
    {
        return new self([], $source);
    }

    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    /** @return list<ScheduleDraftEntry> */
    public function applicableEntries(): array
    {
        return array_values(array_filter($this->entries, static fn (ScheduleDraftEntry $entry): bool => $entry->isApplicable()));
    }

    /** @return list<ScheduleDraftEntry> */
    public function unresolvedEntries(): array
    {
        return array_values(array_filter($this->entries, static fn (ScheduleDraftEntry $entry): bool => !$entry->isApplicable()));
    }

    public function firstDate(): ?WorkDate
    {
        return $this->entries[0]->date ?? null;
    }

    public function lastDate(): ?WorkDate
    {
        $last = $this->entries[\count($this->entries) - 1] ?? null;

        return $last?->date;
    }

    /**
     * Later wins: dictating "1 y 2 mañana, 2 tarde" means the second day is a
     * late shift, the same way a second tap in paint mode replaces the first.
     *
     * @param list<ScheduleDraftEntry> $entries
     *
     * @return list<ScheduleDraftEntry>
     */
    private static function deduplicated(array $entries): array
    {
        $byDate = [];
        foreach ($entries as $entry) {
            $byDate[(string) $entry->date] = $entry;
        }
        ksort($byDate);

        return array_values($byDate);
    }
}
