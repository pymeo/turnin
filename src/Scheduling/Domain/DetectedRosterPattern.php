<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

final readonly class DetectedRosterPattern
{
    /** @param list<PatternSlotProposal> $slots */
    public function __construct(public array $slots, public WorkDate $repeatsFrom, public int $observedCycles)
    {
    }

    public function length(): int
    {
        return \count($this->slots);
    }

    public function sequence(): string
    {
        return implode(' · ', array_map(static fn (PatternSlotProposal $slot): string => $slot->abbreviation, $this->slots));
    }
}
