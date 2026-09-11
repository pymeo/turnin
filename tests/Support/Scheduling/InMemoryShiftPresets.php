<?php

declare(strict_types=1);

namespace App\Tests\Support\Scheduling;

use App\Scheduling\Domain\ShiftPreset;
use App\Scheduling\Domain\ShiftPresets;

final class InMemoryShiftPresets implements ShiftPresets
{
    /** @var array<string, ShiftPreset> */
    private array $presets = [];

    /** @param list<ShiftPreset> $seed */
    public function __construct(array $seed = [])
    {
        $this->saveAll($seed);
    }

    public function forAssignment(string $workerAssignmentId): array
    {
        $found = array_values(array_filter($this->presets, static fn (ShiftPreset $preset): bool => $preset->workerAssignmentId() === $workerAssignmentId));
        usort($found, static fn (ShiftPreset $a, ShiftPreset $b): int => $a->position() <=> $b->position());

        return $found;
    }

    public function byId(string $workerAssignmentId, string $presetId): ?ShiftPreset
    {
        $preset = $this->presets[$presetId] ?? null;

        return null !== $preset && $preset->workerAssignmentId() === $workerAssignmentId ? $preset : null;
    }

    public function save(ShiftPreset $preset): void
    {
        $this->presets[$preset->id()] = $preset;
    }

    public function saveAll(array $presets): void
    {
        foreach ($presets as $preset) {
            $this->save($preset);
        }
    }
}
