<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\ShiftPresets;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Retires a preset without deleting it. Days from last March still name it, and
 * a calendar that forgets why a shift was called "Guardia" is a calendar nobody
 * can audit.
 */
final readonly class DeactivateShiftPresetHandler
{
    public function __construct(private RosterWorkspace $workspace, private ShiftPresets $presets, private ClockInterface $clock)
    {
    }

    public function __invoke(DeactivateShiftPreset $command): void
    {
        $worker = $this->workspace->require($command->workerId, $command->assignmentId);
        $preset = $this->presets->byId($worker->assignmentId, $command->presetId)
            ?? throw new InvalidArgumentException('Ese turno no existe.');

        $preset->deactivate($this->clock->now());
        $this->presets->save($preset);
    }
}
