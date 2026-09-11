<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * Turns whatever a worker says into one of *their* presets.
 *
 * Deliberately not a hardcoded M/T/N table: someone whose rota is "Guardia 24h"
 * says "guardia", and a parser that only knows the three classic bands would
 * shrug at the one word they actually use.
 */
final readonly class ShiftPresetResolver
{
    /** @var array<string, ShiftPreset> */
    private array $byId;

    /** @var array<string, ShiftPreset> */
    private array $bySpokenForm;

    /** @var list<ShiftPreset> */
    private array $ordered;

    /** @param list<ShiftPreset> $presets */
    public function __construct(array $presets)
    {
        $byId = [];
        $byForm = [];
        $active = [];

        foreach ($presets as $preset) {
            $byId[$preset->id()] = $preset;
            if (!$preset->active()) {
                continue;
            }
            $active[] = $preset;
            foreach ($preset->spokenForms() as $form) {
                $normalized = SpokenTerm::normalize($form);
                // First preset to claim a word keeps it: position order makes
                // that "the one nearest the top of their own list".
                if ('' !== $normalized && !isset($byForm[$normalized])) {
                    $byForm[$normalized] = $preset;
                }
            }
        }

        usort($active, static fn (ShiftPreset $a, ShiftPreset $b): int => [$a->position(), $a->name()] <=> [$b->position(), $b->name()]);

        $this->byId = $byId;
        $this->bySpokenForm = $byForm;
        $this->ordered = $active;
    }

    public function byId(string $id): ?ShiftPreset
    {
        return $this->byId[$id] ?? null;
    }

    public function bySpokenForm(string $term): ?ShiftPreset
    {
        return $this->bySpokenForm[SpokenTerm::normalize($term)] ?? null;
    }

    /**
     * Longest first, so "turno de noche" is tried before "noche".
     *
     * @return list<string>
     */
    public function spokenForms(): array
    {
        $forms = array_keys($this->bySpokenForm);
        usort($forms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $forms;
    }

    /** @return list<ShiftPreset> */
    public function active(): array
    {
        return $this->ordered;
    }

    public function isEmpty(): bool
    {
        return [] === $this->ordered;
    }

    public function nextPosition(): int
    {
        $last = $this->ordered[\count($this->ordered) - 1] ?? null;

        return null === $last ? 1 : $last->position() + 1;
    }
}
