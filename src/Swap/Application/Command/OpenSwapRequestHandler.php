<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\Swap\Application\SwapAccessDenied;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapIdGenerator;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDate;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * "Quiero quitarme este turno.".
 *
 * Publishing changes nothing about the roster: the shift is still worked by the
 * person who published it until an agreement exists, and agreements are the
 * next slice. All this does is make the shift visible to the group.
 */
final readonly class OpenSwapRequestHandler
{
    public function __construct(
        private SwapWorkspace $workspace,
        private SwapRequests $requests,
        private RosteredDays $rosteredDays,
        private SwapIdGenerator $ids,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(OpenSwapRequest $command): string
    {
        $group = $this->groupFor($command);
        $date = WorkDate::fromString($command->date);

        // Scheduling is the authority on what the worker actually has that day.
        // A rest day, a day they never filled in, or somebody else's calendar
        // all end here.
        $day = $this->rosteredDays->dayFor($command->workerAssignmentId, (string) $date);
        if (!$day->isWorking()) {
            throw new InvalidArgumentException('Solo puedes publicar un día en el que trabajas.');
        }

        $existing = $this->requests->openFor($command->workerAssignmentId, $date);
        if (null !== $existing) {
            // A second tap on a phone is not a second request.
            return $existing->id();
        }

        $request = SwapRequest::open(
            $this->ids->next(),
            $command->workerId,
            $command->workerAssignmentId,
            $group->poolId,
            $day->rosterDayId,
            $date,
            $day->shiftKind,
            $this->workspace->today($group),
            $this->clock->now(),
        );
        $this->requests->save($request);

        return $request->id();
    }

    private function groupFor(OpenSwapRequest $command): SwapGroup
    {
        if (null !== $command->swapPoolId) {
            return $this->workspace->requireGroupOfAssignment($command->workerId, $command->workerAssignmentId, $command->swapPoolId);
        }

        // No pool given: the primary group of that assignment, which is the one
        // the calendar belongs to.
        $groups = $this->workspace->groupsForAssignment($command->workerId, $command->workerAssignmentId);
        foreach ($groups as $group) {
            if ($group->primary) {
                return $group;
            }
        }

        return $groups[0] ?? throw SwapAccessDenied::noAssignment();
    }
}
