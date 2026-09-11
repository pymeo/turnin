<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\RosterIdGenerator;
use App\Scheduling\Domain\ShiftPreset;
use App\Scheduling\Domain\ShiftPresets;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class CopyShiftPresetsHandler
{
    public function __construct(private RosterWorkspace $workspace, private ShiftPresets $presets, private RosterIdGenerator $ids, private ClockInterface $clock)
    {
    }

    public function __invoke(CopyShiftPresets $command): void
    {
        $source = $this->workspace->requireAssignment($command->workerId, $command->sourceAssignmentId);
        $target = $this->workspace->requireAssignment($command->workerId, $command->targetAssignmentId);
        if ([] !== $this->presets->forAssignment($target->assignmentId)) {
            throw new InvalidArgumentException('Este calendario ya tiene turnos configurados.');
        }
        $now = $this->clock->now();
        $copies = [];
        foreach ($this->presets->forAssignment($source->assignmentId) as $preset) {
            if ($preset->active()) {
                $copies[] = ShiftPreset::create($this->ids->next(), $target->assignmentId, $preset->name(), $preset->abbreviation(), $preset->window(), $preset->kind(), $preset->aliases(), $preset->position(), $now, $preset->color());
            }
        }
        $this->presets->saveAll($copies);
    }
}
