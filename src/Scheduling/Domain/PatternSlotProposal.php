<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

final readonly class PatternSlotProposal
{
    public function __construct(public PatternSlotType $type, public ?string $presetId, public string $label, public string $abbreviation)
    {
    }

    public static function rest(): self
    {
        return new self(PatternSlotType::REST, null, 'Libre', 'L');
    }

    public static function fromPreset(ShiftPreset $preset): self
    {
        return new self(PatternSlotType::SHIFT, $preset->id(), $preset->name(), $preset->abbreviation());
    }

    /** Built from a day that already happened, so it uses the stored snapshot. */
    public static function fromSegment(ShiftSegment $segment): self
    {
        return new self(PatternSlotType::SHIFT, $segment->presetId, $segment->labelSnapshot, $segment->abbreviationSnapshot);
    }
}
