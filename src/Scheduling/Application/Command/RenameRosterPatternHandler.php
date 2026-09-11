<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\RosterPatterns;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class RenameRosterPatternHandler
{
    public function __construct(private RosterWorkspace $workspace, private RosterPatterns $patterns, private ClockInterface $clock)
    {
    }

    public function __invoke(RenameRosterPattern $command): void
    {
        $worker = $this->workspace->require($command->workerId);
        $pattern = $this->patterns->byId($worker->assignmentId, $command->patternId)
            ?? throw new InvalidArgumentException('Ese patrón no existe.');

        $pattern->rename($command->name, $this->clock->now());
        $this->patterns->save($pattern);
    }
}
