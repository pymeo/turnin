<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use InvalidArgumentException;

/**
 * One stretch of work inside a working day. A day normally has exactly one, but
 * the model allows several from the start because split shifts and
 * "shift + on-call" are ordinary in Spanish healthcare, and retrofitting a
 * second segment onto a one-shift-per-day schema means migrating live rosters.
 *
 * The label, abbreviation and hours are a *snapshot* of the preset that created
 * the segment, copied at the moment it was applied. Editing "Mañana" from
 * 08:00–15:00 to 07:30–14:30 next month must not rewrite what the worker
 * actually did last March — see docs/adr/0008-roster-and-calendar-model.md.
 */
final readonly class ShiftSegment
{
    public function __construct(
        public string $id,
        public ?string $presetId,
        public string $labelSnapshot,
        public string $abbreviationSnapshot,
        public ShiftWindow $window,
        public ShiftKind $kind,
        public int $position,
    ) {
        if ('' === trim($this->labelSnapshot) || '' === trim($this->abbreviationSnapshot)) {
            throw new InvalidArgumentException('A shift segment needs a label and an abbreviation.');
        }
        $this->window->guardAgainstEmptyWindow();
    }

    public static function fromPreset(string $id, ShiftPreset $preset, int $position): self
    {
        return new self($id, $preset->id(), $preset->name(), $preset->abbreviation(), $preset->window(), $preset->kind(), $position);
    }

    public function endsNextDay(): bool
    {
        return $this->window->endsNextDay();
    }

    public function describe(): string
    {
        return \sprintf('%s %s', $this->labelSnapshot, $this->window);
    }

    public function matches(self $other): bool
    {
        return $this->abbreviationSnapshot === $other->abbreviationSnapshot
            && $this->kind === $other->kind
            && $this->window->equals($other->window);
    }
}
