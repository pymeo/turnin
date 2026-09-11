<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\RosterIdGenerator;
use App\Scheduling\Domain\ShiftKind;
use App\Scheduling\Domain\ShiftPreset;
use App\Scheduling\Domain\ShiftPresets;
use App\Scheduling\Domain\ShiftWindow;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class SaveShiftPresetHandler
{
    public function __construct(
        private RosterWorkspace $workspace,
        private ShiftPresets $presets,
        private RosterIdGenerator $ids,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SaveShiftPreset $command): string
    {
        $worker = $this->workspace->require($command->workerId);
        $now = $this->clock->now();
        $window = ShiftWindow::fromStrings($command->start, $command->end);
        $kind = ShiftKind::tryFrom($command->kind) ?? ShiftKind::OTHER;

        if (null === $command->presetId) {
            $resolver = $this->workspace->presetsFor($worker);
            $preset = ShiftPreset::create($this->ids->next(), $worker->assignmentId, $command->name, $command->abbreviation, $window, $kind, $command->aliases, $resolver->nextPosition(), $now);
            $this->presets->save($preset);

            return $preset->id();
        }

        // Scoped by assignment, so a preset id from another account resolves to
        // nothing rather than to somebody else's shift.
        $preset = $this->presets->byId($worker->assignmentId, $command->presetId)
            ?? throw new InvalidArgumentException('Ese turno no existe.');

        $preset->reshape($command->name, $command->abbreviation, $window, $kind, $command->aliases, $now);
        $this->presets->save($preset);

        return $preset->id();
    }
}
