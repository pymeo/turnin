<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\Availabilities;
use App\Swap\Domain\Availability;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDate;
use App\Swap\Domain\WorkerDisplayNames;

/**
 * The exchange half of a calendar day, computed server side so the day sheet
 * does not have to decide anything. A day the worker is already working cannot
 * also be offered as availability, and only a future day can be either.
 */
final readonly class GetDayExchangeHandler
{
    public function __construct(
        private SwapWorkspace $workspace,
        private SwapRequests $requests,
        private Availabilities $availabilities,
        private RosteredDays $rosteredDays,
        private WorkerDisplayNames $names,
    ) {
    }

    public function __invoke(GetDayExchange $query): DayExchangeView
    {
        $groups = $this->workspace->groupsForAssignment($query->workerId, $query->workerAssignmentId);
        $date = WorkDate::fromString($query->date);
        $day = $this->rosteredDays->dayFor($query->workerAssignmentId, (string) $date);

        $future = [] !== $groups && $date->isAfter($this->workspace->today($groups[0]));
        $request = $this->requests->openFor($query->workerAssignmentId, $date);
        $availableIn = $this->availabilities->activePoolsOnDate($query->workerId, $date);

        $candidates = [];
        if (null !== $request) {
            $offers = $this->availabilities->activeInPoolOnDate($request->swapPoolId(), $date, $request->shiftKind(), $query->workerId);
            $names = $this->names->forWorkers(array_values(array_unique(array_map(
                static fn (Availability $availability): string => $availability->workerId(),
                $offers,
            ))));
            $label = $this->labelOf($groups, $request->swapPoolId());
            $candidates = array_map(static fn (Availability $availability): CandidateView => new CandidateView(
                $availability->id(),
                $names[$availability->workerId()] ?? 'Un compañero',
                $label,
            ), $offers);
        }

        return new DayExchangeView(
            (string) $date,
            $day->state->value,
            $future && $day->isWorking() && null === $request,
            $future && !$day->isWorking(),
            $request?->id(),
            \count($candidates),
            array_map(static fn (SwapGroup $group): SwapGroupView => new SwapGroupView(
                $group->poolId,
                $group->assignmentId,
                $group->label(),
                $group->fullLabel(),
                $group->workplaceName,
                $group->primary,
            ), $groups),
            $availableIn,
            $candidates,
        );
    }

    /** @param list<SwapGroup> $groups */
    private function labelOf(array $groups, string $poolId): string
    {
        foreach ($groups as $group) {
            if ($group->poolId === $poolId) {
                return $group->label();
            }
        }

        return '';
    }
}
