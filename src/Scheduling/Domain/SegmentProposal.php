<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * A segment a draft would create, before anything is written. Carries the
 * values already resolved from the preset so the preview shows exactly what
 * will be stored.
 */
final readonly class SegmentProposal
{
    public function __construct(
        public ?string $presetId,
        public string $label,
        public string $abbreviation,
        public ShiftWindow $window,
        public ShiftKind $kind,
        public ShiftColor $color = ShiftColor::SLATE,
    ) {
    }

    public static function fromPreset(ShiftPreset $preset): self
    {
        return new self($preset->id(), $preset->name(), $preset->abbreviation(), $preset->window(), $preset->kind(), $preset->color());
    }
}
