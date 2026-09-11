<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A worker's own quick button: "Mañana, M, 08:00–15:00". A template, not a
 * record — applying it copies its values into a segment, so editing the preset
 * changes what the next tap produces and nothing that already happened.
 *
 * There is no "Libre" preset. Being off is {@see RosterDayState::REST}, a
 * property of the day; modelling it as a shift would put rest days in every
 * query that counts worked hours.
 */
final class ShiftPreset
{
    private const MAX_ABBREVIATION_LENGTH = 3;

    /** @param list<string> $aliases */
    private function __construct(
        private readonly string $id,
        private readonly string $workerAssignmentId,
        private string $name,
        private string $abbreviation,
        private ShiftWindow $window,
        private ShiftKind $kind,
        private ShiftColor $color,
        private array $aliases,
        private int $position,
        private bool $active,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        $this->guard();
    }

    /** @param list<string> $aliases */
    public static function create(string $id, string $workerAssignmentId, string $name, string $abbreviation, ShiftWindow $window, ShiftKind $kind, array $aliases, int $position, DateTimeImmutable $now, ?ShiftColor $color = null): self
    {
        return new self($id, $workerAssignmentId, $name, $abbreviation, $window, $kind, $color ?? ShiftColor::suggestedFor($kind), $aliases, $position, true, $now, $now);
    }

    /** @param list<string> $aliases */
    public static function restore(string $id, string $workerAssignmentId, string $name, string $abbreviation, ShiftWindow $window, ShiftKind $kind, array $aliases, int $position, bool $active, DateTimeImmutable $createdAt, DateTimeImmutable $updatedAt, ?ShiftColor $color = null): self
    {
        return new self($id, $workerAssignmentId, $name, $abbreviation, $window, $kind, $color ?? ShiftColor::suggestedFor($kind), $aliases, $position, $active, $createdAt, $updatedAt);
    }

    /** @param list<string> $aliases */
    public function reshape(string $name, string $abbreviation, ShiftWindow $window, ShiftKind $kind, array $aliases, DateTimeImmutable $now, ?ShiftColor $color = null): void
    {
        $this->name = $name;
        $this->abbreviation = $abbreviation;
        $this->window = $window;
        $this->kind = $kind;
        $this->color = $color ?? $this->color;
        $this->aliases = $aliases;
        $this->updatedAt = $now;
        $this->guard();
    }

    public function moveTo(int $position, DateTimeImmutable $now): void
    {
        $this->position = $position;
        $this->updatedAt = $now;
    }

    /**
     * Presets are never deleted. A segment created three months ago names the
     * preset it came from, and a foreign key that dangles is a report that lies.
     */
    public function deactivate(DateTimeImmutable $now): void
    {
        $this->active = false;
        $this->updatedAt = $now;
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

    public function abbreviation(): string
    {
        return $this->abbreviation;
    }

    public function window(): ShiftWindow
    {
        return $this->window;
    }

    public function kind(): ShiftKind
    {
        return $this->kind;
    }

    public function color(): ShiftColor
    {
        return $this->color;
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return $this->aliases;
    }

    /**
     * Everything the text parser may call this preset: its name, its
     * abbreviation and whatever the worker added ("guardia", "g", "24 horas").
     *
     * @return list<string>
     */
    public function spokenForms(): array
    {
        return array_values(array_unique([$this->name, $this->abbreviation, ...$this->aliases]));
    }

    public function position(): int
    {
        return $this->position;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function guard(): void
    {
        $this->name = trim($this->name);
        $this->abbreviation = trim($this->abbreviation);

        if ('' === $this->name || mb_strlen($this->name) > 40) {
            throw new InvalidArgumentException('El nombre del turno debe tener entre 1 y 40 caracteres.');
        }
        if ('' === $this->abbreviation || mb_strlen($this->abbreviation) > self::MAX_ABBREVIATION_LENGTH) {
            throw new InvalidArgumentException(\sprintf('La abreviatura debe tener entre 1 y %d caracteres para caber en la celda del calendario.', self::MAX_ABBREVIATION_LENGTH));
        }
        if ('' === trim($this->workerAssignmentId)) {
            throw new InvalidArgumentException('A shift preset belongs to a worker assignment.');
        }
        $this->window->guardAgainstEmptyWindow();

        $aliases = [];
        foreach ($this->aliases as $alias) {
            $alias = trim($alias);
            if ('' !== $alias) {
                $aliases[] = $alias;
            }
        }
        $this->aliases = array_values(array_unique($aliases));
    }
}
