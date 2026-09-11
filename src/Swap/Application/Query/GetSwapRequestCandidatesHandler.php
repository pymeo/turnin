<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\Availabilities;
use App\Swap\Domain\Availability;
use App\Swap\Domain\WorkerDisplayNames;

/**
 * The people who said they could work that day in that group.
 *
 * That is the whole rule, and it is deliberately that blunt: no legal rest
 * checks, no overlap arithmetic, no ranking. Those belong to a matcher that can
 * explain itself, and a list ordered by a rule nobody has written yet would be
 * a ranking pretending to be a fact.
 */
final readonly class GetSwapRequestCandidatesHandler
{
    public function __construct(
        private SwapWorkspace $workspace,
        private Availabilities $availabilities,
        private WorkerDisplayNames $names,
    ) {
    }

    /** @return list<CandidateView> */
    public function __invoke(GetSwapRequestCandidates $query): array
    {
        // Only the author sees who offered. A colleague browsing the group can
        // see the shift, never the queue of people interested in it.
        $request = $this->workspace->requireOwnRequest($query->workerId, $query->requestId);
        $group = $this->workspace->requireGroup($query->workerId, $request->swapPoolId());

        $offers = $this->availabilities->activeInPoolOnDate($request->swapPoolId(), $request->workDate(), $request->shiftKind(), $request->workerId());
        if ([] === $offers) {
            return [];
        }

        $names = $this->names->forWorkers(array_values(array_unique(array_map(
            static fn (Availability $availability): string => $availability->workerId(),
            $offers,
        ))));

        return array_map(static fn (Availability $availability): CandidateView => new CandidateView(
            $names[$availability->workerId()] ?? 'Un compañero',
            $group->label(),
        ), $offers);
    }
}
