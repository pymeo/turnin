<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

final readonly class ResolvedDraftEntry
{
    public function __construct(
        public ScheduleDraftEntry $entry,
        public ?RosterDay $existing,
        public bool $conflicting,
        public bool $applies,
    ) {
    }

    public function date(): WorkDate
    {
        return $this->entry->date;
    }

    /** What the day says today, for the "ya tiene Tarde" line in the preview. */
    public function existingDescription(): ?string
    {
        if (null === $this->existing) {
            return null;
        }
        if ($this->existing->isRest()) {
            return 'Libre';
        }

        return implode(' · ', array_map(static fn (ShiftSegment $segment): string => $segment->describe(), $this->existing->segments()));
    }
}
