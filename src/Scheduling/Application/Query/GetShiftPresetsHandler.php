<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\ShiftPreset;
use App\Scheduling\Domain\ShiftPresets;

final readonly class GetShiftPresetsHandler
{
    public function __construct(private RosterWorkspace $workspace, private ShiftPresets $presets)
    {
    }

    /** @return list<ShiftPresetView> */
    public function __invoke(GetShiftPresets $query): array
    {
        $worker = $this->workspace->require($query->workerId);

        $presets = $this->presets->forAssignment($worker->assignmentId);
        if (!$query->includeInactive) {
            $presets = array_values(array_filter($presets, static fn (ShiftPreset $preset): bool => $preset->active()));
        }

        return array_map(static fn (ShiftPreset $preset): ShiftPresetView => new ShiftPresetView(
            $preset->id(),
            $preset->name(),
            $preset->abbreviation(),
            (string) $preset->window()->start,
            (string) $preset->window()->end,
            $preset->kind()->value,
            $preset->kind()->tone(),
            $preset->window()->endsNextDay(),
            $preset->position(),
            $preset->active(),
            $preset->aliases(),
        ), $presets);
    }
}
