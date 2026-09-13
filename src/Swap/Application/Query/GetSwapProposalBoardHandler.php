<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\ShiftBalance;
use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDateLabel;
use App\Swap\Domain\WorkerDisplayNames;

final readonly class GetSwapProposalBoardHandler
{
    public function __construct(private SwapProposals $proposals, private SwapRequests $requests, private RosteredDays $days, private WorkerDisplayNames $names)
    {
    }

    /** @return list<SwapProposalView> */
    public function __invoke(GetSwapProposalBoard $query): array
    {
        $proposals = $this->proposals->involving($query->workerId);
        $workerIds = [];
        $pairs = [];
        foreach ($proposals as $proposal) {
            $request = $this->requests->byId($proposal->requestId());
            if (null === $request) {
                continue;
            }
            $workerIds[] = $proposal->requestOwnerId();
            $workerIds[] = $proposal->proposerId();
            $pairs[] = [$request->workerAssignmentId(), (string) $request->workDate()];
            if (null !== $proposal->offeredWorkDate()) {
                $pairs[] = [$proposal->proposerAssignmentId(), (string) $proposal->offeredWorkDate()];
            }
        }
        $days = $this->days->daysFor($pairs);
        $names = $this->names->forWorkers(array_values(array_unique($workerIds)));
        $views = [];
        foreach ($proposals as $proposal) {
            $request = $this->requests->byId($proposal->requestId());
            if (null === $request) {
                continue;
            }
            $requested = $days[RosteredDay::keyFor($request->workerAssignmentId(), (string) $request->workDate())] ?? null;
            if (null === $requested || !$requested->isWorking()) {
                continue;
            }
            $offered = null === $proposal->offeredWorkDate() ? null : ($days[RosteredDay::keyFor($proposal->proposerAssignmentId(), (string) $proposal->offeredWorkDate())] ?? null);
            $incoming = $proposal->requestOwnerId() === $query->workerId;
            $otherId = $incoming ? $proposal->proposerId() : $proposal->requestOwnerId();
            $offeredMinutes = $offered?->durationMinutes();
            $ownerBalance = null === $offeredMinutes ? 0 : ShiftBalance::forProposer($requested->durationMinutes(), $offeredMinutes)->forRequestOwner()->minutes;
            $views[] = new SwapProposalView($proposal->id(), $proposal->kind()->value, $proposal->status()->value, $incoming, $names[$otherId] ?? 'Un compañero', (string) $request->workDate(), WorkDateLabel::headline($request->workDate()), $requested->hours(), $requested->durationLabel(), $requested->durationMinutes(), $requested->shiftLabel, null === $offered ? null : $offered->date, null === $proposal->offeredWorkDate() ? null : WorkDateLabel::headline($proposal->offeredWorkDate()), $offered?->hours(), $offered?->durationLabel(), $offeredMinutes, $offered?->shiftLabel, $ownerBalance);
        }

        return $views;
    }
}
