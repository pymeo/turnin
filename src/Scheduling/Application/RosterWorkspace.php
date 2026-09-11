<?php

declare(strict_types=1);

namespace App\Scheduling\Application;

use App\Scheduling\Domain\AssignedWorker;
use App\Scheduling\Domain\AssignedWorkers;
use App\Scheduling\Domain\ShiftPresetResolver;
use App\Scheduling\Domain\ShiftPresets;

/**
 * The two lookups every calendar use case starts with: whose roster is this,
 * and what are their shift buttons.
 *
 * It exists so that "resolve the assignment from the session, never from the
 * request" is written once. Ten handlers each doing it themselves is ten places
 * for one of them to trust the browser instead.
 */
final readonly class RosterWorkspace
{
    public function __construct(private AssignedWorkers $workers, private ShiftPresets $presets)
    {
    }

    public function require(string $workerId): AssignedWorker
    {
        return $this->workers->primaryFor($workerId) ?? throw RosterAccessDenied::noAssignment();
    }

    public function find(string $workerId): ?AssignedWorker
    {
        return $this->workers->primaryFor($workerId);
    }

    public function presetsFor(AssignedWorker $worker): ShiftPresetResolver
    {
        return new ShiftPresetResolver($this->presets->forAssignment($worker->assignmentId));
    }
}
