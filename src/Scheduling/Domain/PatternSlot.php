<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use InvalidArgumentException;

/**
 * One step of a rotation: "the fifth day is a night", "the seventh is off".
 *
 * Structured rather than the string "M,M,T,T,N,N,L,L,L", because the string
 * cannot answer "how many nights does this rotation contain", cannot survive
 * the worker renaming a preset, and has to be re-parsed everywhere it is used.
 */
final readonly class PatternSlot
{
    public function __construct(public int $position, public PatternSlotType $type, public ?string $shiftPresetId)
    {
        if ($this->position < 1) {
            throw new InvalidArgumentException('Pattern slots are numbered from 1.');
        }
        if (PatternSlotType::SHIFT === $this->type && null === $this->shiftPresetId) {
            throw new InvalidArgumentException('A shift slot needs the preset it repeats.');
        }
        if (PatternSlotType::REST === $this->type && null !== $this->shiftPresetId) {
            throw new InvalidArgumentException('A rest slot is not a shift and carries no preset.');
        }
    }

    public static function shift(int $position, string $shiftPresetId): self
    {
        return new self($position, PatternSlotType::SHIFT, $shiftPresetId);
    }

    public static function rest(int $position): self
    {
        return new self($position, PatternSlotType::REST, null);
    }
}
