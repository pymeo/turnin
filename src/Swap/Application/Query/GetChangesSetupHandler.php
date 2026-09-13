<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDateLabel;

final readonly class GetChangesSetupHandler
{
    public function __construct(
        private SwapWorkspace $workspace,
        private RosteredDays $rosteredDays,
        private SwapRequests $requests,
    ) {
    }

    public function __invoke(GetChangesSetup $query): ChangesSetupView
    {
        $groups = $this->workspace->groupsFor($query->workerId);
        if ([] === $groups) {
            return new ChangesSetupView([], [], '', '');
        }

        $today = $this->workspace->today($groups[0]);
        $from = $today->plusDays(1);
        $to = $today->plusDays(120);
        $assignments = array_values(array_unique(array_map(static fn (SwapGroup $group): string => $group->assignmentId, $groups)));
        $primaryByAssignment = [];
        foreach ($groups as $group) {
            if (!isset($primaryByAssignment[$group->assignmentId]) || $group->primary) {
                $primaryByAssignment[$group->assignmentId] = $group;
            }
        }

        $openByDay = [];
        foreach ($this->requests->openByWorker($query->workerId, $today) as $request) {
            $openByDay[$request->workerAssignmentId().'|'.$request->workDate()] = $request;
        }

        $shifts = [];
        $workingDates = [];
        foreach ($this->rosteredDays->inRangeForAssignments($assignments, (string) $from, (string) $to) as $day) {
            if (!$day->isWorking()) {
                continue;
            }
            $group = $primaryByAssignment[$day->assignmentId] ?? null;
            if (!$group instanceof SwapGroup) {
                continue;
            }
            $workingDates[$day->date] = true;
            $open = $openByDay[$day->assignmentId.'|'.$day->date] ?? null;
            $shifts[] = new UpcomingShiftView(
                $day->assignmentId,
                $group->poolId,
                $day->date,
                WorkDateLabel::headline(\App\Swap\Domain\WorkDate::fromString($day->date)),
                $day->shiftLabel,
                $day->hours(),
                $day->durationMinutes(),
                $day->durationLabel(),
                $day->shiftKind->value,
                $group->workplaceName,
                $group->label(),
                null !== $open,
                $open?->id(),
            );
        }

        usort($shifts, static fn (UpcomingShiftView $a, UpcomingShiftView $b): int => $a->date <=> $b->date);

        return new ChangesSetupView($shifts, array_keys($workingDates), (string) $from, (string) $to);
    }
}
