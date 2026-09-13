<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\Availabilities;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDate;
use App\Swap\Domain\WorkDateLabel;
use App\Swap\Domain\WorkerDisplayNames;

/**
 * What colleagues need covering, in the groups this worker actually belongs to.
 *
 * The pools come from the session, never from the request, so narrowing by
 * group can only ever narrow — it cannot reach a pool the worker is not in.
 */
final readonly class GetOpenSwapRequestsHandler
{
    public function __construct(
        private SwapWorkspace $workspace,
        private SwapRequests $requests,
        private RosteredDays $rosteredDays,
        private Availabilities $availabilities,
        private WorkerDisplayNames $names,
    ) {
    }

    /** @return list<OpenSwapRequestView> */
    public function __invoke(GetOpenSwapRequests $query): array
    {
        $groups = $this->workspace->groupsFor($query->workerId);
        if ([] === $groups) {
            return [];
        }
        if (null !== $query->swapPoolId) {
            $groups = [$this->workspace->requireGroup($query->workerId, $query->swapPoolId)];
        }

        $byPool = [];
        foreach ($groups as $group) {
            $byPool[$group->poolId] = $group;
        }

        $today = $this->workspace->today($groups[0]);
        $open = $this->requests->openInPools(array_keys($byPool), $today, $query->workerId);
        if ([] === $open) {
            return [];
        }

        // Three bulk lookups, whatever the number of cards: the shifts, the
        // names, and which of these days this worker has already offered for.
        $days = $this->rosteredDays->daysFor(array_map(
            static fn (SwapRequest $request): array => [$request->workerAssignmentId(), (string) $request->workDate()],
            $open,
        ));
        $names = $this->names->forWorkers(array_values(array_unique(array_map(
            static fn (SwapRequest $request): string => $request->workerId(),
            $open,
        ))));
        $mine = $this->alreadyOffered($query->workerId, $open);

        $views = [];
        foreach ($open as $request) {
            $day = $days[RosteredDay::keyFor($request->workerAssignmentId(), (string) $request->workDate())] ?? null;
            // The author cleared or changed the day after publishing. The
            // request is stale, so it is not shown rather than shown wrong.
            if (null === $day || !$day->isWorking()) {
                continue;
            }
            $group = $byPool[$request->swapPoolId()];
            $views[] = new OpenSwapRequestView(
                $request->id(),
                (string) $request->workDate(),
                WorkDateLabel::headline($request->workDate()),
                $day->shiftLabel,
                $day->abbreviation,
                $day->hours(),
                $day->durationMinutes(),
                $day->durationLabel(),
                $day->endsNextDay,
                $day->colorKey,
                $group->label(),
                $group->workplaceName,
                $names[$request->workerId()] ?? 'Un compañero',
                isset($mine[$request->swapPoolId().'|'.$request->workDate().'|'.$request->shiftKind()->value]),
            );
        }

        return $views;
    }

    /**
     * @param list<SwapRequest> $open
     *
     * @return array<string, true>
     */
    private function alreadyOffered(string $workerId, array $open): array
    {
        $offered = [];
        foreach ($this->availabilities->activePoolsOnDates($workerId, $this->datesOf($open)) as $date => $poolIds) {
            foreach ($poolIds as $poolId) {
                [$workDate, $kind] = explode('|', $date, 2);
                $offered[$poolId.'|'.$workDate.'|'.$kind] = true;
            }
        }

        return $offered;
    }

    /**
     * @param list<SwapRequest> $open
     *
     * @return list<WorkDate>
     */
    private function datesOf(array $open): array
    {
        $dates = [];
        foreach ($open as $request) {
            $dates[(string) $request->workDate()] = $request->workDate();
        }

        return array_values($dates);
    }
}
