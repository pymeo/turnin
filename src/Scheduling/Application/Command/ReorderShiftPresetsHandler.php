<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\ShiftPresets;
use Psr\Clock\ClockInterface;

/**
 * The order of the paint palette is the order of the buttons a thumb reaches
 * for, so the worker owns it.
 */
final readonly class ReorderShiftPresetsHandler
{
    public function __construct(private RosterWorkspace $workspace, private ShiftPresets $presets, private ClockInterface $clock)
    {
    }

    public function __invoke(ReorderShiftPresets $command): void
    {
        $worker = $this->workspace->require($command->workerId, $command->assignmentId);
        $now = $this->clock->now();
        $moved = [];

        foreach ($command->presetIdsInOrder as $position => $presetId) {
            $preset = $this->presets->byId($worker->assignmentId, $presetId);
            if (null === $preset) {
                continue;
            }
            $preset->moveTo($position + 1, $now);
            $moved[] = $preset;
        }

        $this->presets->saveAll($moved);
    }
}
