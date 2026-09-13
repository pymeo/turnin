<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDateLabel;

/**
 * "Mis turnos publicados", with how many people have said they could do each.
 * The count is the only thing this phase can honestly tell the author — who is
 * available, not who has agreed.
 */
final readonly class GetMySwapRequestsHandler
{
    public function __construct(
        private SwapWorkspace $workspace,
        private SwapRequests $requests,
        private RosteredDays $rosteredDays,
    ) {
    }

    /** @return list<MySwapRequestView> */
    public function __invoke(GetMySwapRequests $query): array
    {
        $groups = $this->workspace->groupsFor($query->workerId);
        if ([] === $groups) {
            return [];
        }

        $byPool = [];
        foreach ($groups as $group) {
            $byPool[$group->poolId] = $group;
        }

        $open = $this->requests->openByWorker($query->workerId, $this->workspace->today($groups[0]));
        if ([] === $open) {
            return [];
        }

        $days = $this->rosteredDays->daysFor(array_map(
            static fn (SwapRequest $request): array => [$request->workerAssignmentId(), (string) $request->workDate()],
            $open,
        ));
        $counts = $this->requests->candidateCounts(array_map(
            static fn (SwapRequest $request): string => $request->id(),
            $open,
        ));

        $views = [];
        foreach ($open as $request) {
            $day = $days[RosteredDay::keyFor($request->workerAssignmentId(), (string) $request->workDate())] ?? null;
            if (null === $day || !$day->isWorking()) {
                continue;
            }
            $group = $byPool[$request->swapPoolId()] ?? null;
            $views[] = new MySwapRequestView(
                $request->id(),
                (string) $request->workDate(),
                WorkDateLabel::headline($request->workDate()),
                $day->shiftLabel,
                $day->abbreviation,
                $day->hours(),
                $day->durationMinutes(),
                $day->durationLabel(),
                $day->colorKey,
                $group instanceof SwapGroup ? $group->workplaceName : '',
                $group instanceof SwapGroup ? $group->label() : '',
                $counts[$request->id()] ?? 0,
            );
        }

        return $views;
    }
}
