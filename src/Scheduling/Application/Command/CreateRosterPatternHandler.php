<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\PatternSlot;
use App\Scheduling\Domain\RosterIdGenerator;
use App\Scheduling\Domain\RosterPattern;
use App\Scheduling\Domain\RosterPatterns;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class CreateRosterPatternHandler
{
    public function __construct(
        private RosterWorkspace $workspace,
        private RosterPatterns $patterns,
        private RosterIdGenerator $ids,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateRosterPattern $command): string
    {
        $worker = $this->workspace->require($command->workerId, $command->assignmentId);
        $presets = $this->workspace->presetsFor($worker);

        $slots = [];
        foreach ($command->slots as $index => $presetId) {
            if (null === $presetId || '' === $presetId) {
                $slots[] = PatternSlot::rest($index + 1);
                continue;
            }
            if (null === $presets->byId($presetId)) {
                throw new InvalidArgumentException('Uno de los turnos del patrón ya no existe.');
            }
            $slots[] = PatternSlot::shift($index + 1, $presetId);
        }

        $pattern = RosterPattern::create($this->ids->next(), $worker->assignmentId, $command->name, $slots, $this->clock->now());
        $this->patterns->save($pattern);

        return $pattern->id();
    }
}
