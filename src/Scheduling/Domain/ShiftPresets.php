<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

interface ShiftPresets
{
    /** @return list<ShiftPreset> Active and inactive, ordered by position. */
    public function forAssignment(string $workerAssignmentId): array;

    public function byId(string $workerAssignmentId, string $presetId): ?ShiftPreset;

    public function save(ShiftPreset $preset): void;

    /** @param list<ShiftPreset> $presets */
    public function saveAll(array $presets): void;
}
