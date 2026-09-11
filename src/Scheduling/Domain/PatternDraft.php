<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * A rotation the worker described but has not created yet: "mi patrón es
 * mañana mañana tarde tarde noche noche y tres libres".
 *
 * It exists so dictating a rotation lands in the *same* pattern flow as
 * building one by tapping — pick the dates, preview, confirm — instead of
 * growing a second, subtly different way to repeat shifts.
 */
final readonly class PatternDraft
{
    /** @param list<PatternSlotProposal> $slots */
    public function __construct(public array $slots)
    {
    }

    public function isEmpty(): bool
    {
        return [] === $this->slots;
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
