<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\RosterIdGenerator;
use App\Scheduling\Domain\ShiftPreset;
use App\Scheduling\Domain\ShiftPresets;
use App\Scheduling\Domain\SuggestedShiftPresets;
use Psr\Clock\ClockInterface;

/**
 * An empty calendar with no buttons on it is not a starting point, it is a
 * form. Someone opening Turnin for the first time gets Mañana, Tarde and Noche
 * with plausible hours and an obvious way to correct them.
 */
final readonly class EnsureShiftPresetsHandler
{
    public function __construct(
        private RosterWorkspace $workspace,
        private ShiftPresets $presets,
        private RosterIdGenerator $ids,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EnsureShiftPresets $command): void
    {
        $worker = $this->workspace->require($command->workerId);
        if ([] !== $this->presets->forAssignment($worker->assignmentId)) {
            return;
        }

        $now = $this->clock->now();
        $created = [];
        foreach (SuggestedShiftPresets::catalogue() as $position => $blueprint) {
            $created[] = ShiftPreset::create($this->ids->next(), $worker->assignmentId, $blueprint->name, $blueprint->abbreviation, $blueprint->window, $blueprint->kind, $blueprint->aliases, $position + 1, $now);
        }

        $this->presets->saveAll($created);
    }
}
