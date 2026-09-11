<?php

declare(strict_types=1);

namespace App\Scheduling\Application;

use App\Scheduling\Domain\RosterPatterns;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\WorkDate;
use InvalidArgumentException;

/**
 * Loads a rotation that belongs to the signed-in worker and lays it over a
 * range. Shared by the preview and by the write path so that what the worker
 * confirmed is literally what gets applied.
 */
final readonly class RosterPatternExpansion
{
    private const MAXIMUM_RANGE_IN_DAYS = 730;

    public function __construct(private RosterWorkspace $workspace, private RosterPatterns $patterns)
    {
    }

    public function expand(string $workerId, string $patternId, string $from, string $to, ?string $assignmentId = null): ExpandedRosterPattern
    {
        $worker = $this->workspace->require($workerId, $assignmentId);
        $pattern = $this->patterns->byId($worker->assignmentId, $patternId)
            ?? throw new InvalidArgumentException('Ese patrón no existe.');

        $start = WorkDate::fromString($from);
        $end = WorkDate::fromString($to);
        if ($end->isBefore($start)) {
            throw new InvalidArgumentException('La fecha final no puede ser anterior a la inicial.');
        }
        if ($start->daysUntil($end) > self::MAXIMUM_RANGE_IN_DAYS) {
            throw new InvalidArgumentException('Puedes repetir un patrón durante dos años como máximo.');
        }

        return new ExpandedRosterPattern(
            $pattern,
            $pattern->expand($start, $end, $this->workspace->presetsFor($worker), RosterSource::PATTERN),
            $start,
            $end,
        );
    }
}
