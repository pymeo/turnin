<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A rotation the worker repeats: M M T T N N L L L, and then again.
 *
 * Expanding it produces a draft, never rows. Applying three months of rotation
 * without showing what it will do — and what it would overwrite — is how a
 * calendar loses a month of hand-entered shifts in one tap.
 */
final class RosterPattern
{
    /** @param list<PatternSlot> $slots */
    private function __construct(
        private readonly string $id,
        private readonly string $workerAssignmentId,
        private string $name,
        private readonly array $slots,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        if ([] === $this->slots) {
            throw new InvalidArgumentException('Un patrón necesita al menos un día.');
        }
        if (\count($this->slots) > 62) {
            throw new InvalidArgumentException('Un patrón de más de 62 días no es una rotación, es un calendario.');
        }
        foreach ($this->slots as $index => $slot) {
            if ($slot->position !== $index + 1) {
                throw new InvalidArgumentException('Pattern slots must be consecutive and start at 1.');
            }
        }
        $this->name = trim($this->name);
        if ('' === $this->name) {
            throw new InvalidArgumentException('Un patrón necesita un nombre.');
        }
    }

    /** @param list<PatternSlot> $slots */
    public static function create(string $id, string $workerAssignmentId, ?string $name, array $slots, DateTimeImmutable $now): self
    {
        return new self($id, $workerAssignmentId, $name ?? self::suggestedName($slots), $slots, $now, $now);
    }

    /** @param list<PatternSlot> $slots */
    public static function restore(string $id, string $workerAssignmentId, string $name, array $slots, DateTimeImmutable $createdAt, DateTimeImmutable $updatedAt): self
    {
        return new self($id, $workerAssignmentId, $name, $slots, $createdAt, $updatedAt);
    }

    /**
     * Nobody wants to name a rotation before trying it, so we name it for them
     * and let them rename it afterwards.
     *
     * @param list<PatternSlot> $slots
     */
    public static function suggestedName(array $slots): string
    {
        return \sprintf('Patrón de %d días', \count($slots));
    }

    public function rename(string $name, DateTimeImmutable $now): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 60) {
            throw new InvalidArgumentException('El nombre del patrón debe tener entre 1 y 60 caracteres.');
        }
        $this->name = $name;
        $this->updatedAt = $now;
    }

    /**
     * Lays the rotation over a date range, repeating from the first day.
     * Pure: it returns a proposal and touches nothing.
     */
    public function expand(WorkDate $from, WorkDate $to, ShiftPresetResolver $presets, RosterSource $source): ScheduleDraft
    {
        if ($to->isBefore($from)) {
            throw new InvalidArgumentException('La fecha final no puede ser anterior a la inicial.');
        }

        $entries = [];
        $length = \count($this->slots);
        $days = $from->daysUntil($to);
        for ($offset = 0; $offset <= $days; ++$offset) {
            $slot = $this->slots[$offset % $length];
            $date = $from->plusDays($offset);
            if (PatternSlotType::REST === $slot->type) {
                $entries[] = ScheduleDraftEntry::rest($date);
                continue;
            }

            $preset = null === $slot->shiftPresetId ? null : $presets->byId($slot->shiftPresetId);
            if (null === $preset) {
                $entries[] = ScheduleDraftEntry::unresolved($date, \sprintf('El turno del día %d del patrón ya no existe.', $slot->position));
                continue;
            }
            $entries[] = ScheduleDraftEntry::work($date, [SegmentProposal::fromPreset($preset)]);
        }

        return ScheduleDraft::of($entries, $source);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function workerAssignmentId(): string
    {
        return $this->workerAssignmentId;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<PatternSlot> */
    public function slots(): array
    {
        return $this->slots;
    }

    public function length(): int
    {
        return \count($this->slots);
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
