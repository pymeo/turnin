<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\Availabilities;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\SwapTransaction;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class CoverSwapRequestHandler
{
    public function __construct(private SwapTransaction $transaction, private SwapRequests $requests, private Availabilities $availabilities, private SwapWorkspace $workspace, private RosteredDays $rosteredDays, private ClockInterface $clock)
    {
    }

    public function __invoke(CoverSwapRequest $command): void
    {
        $this->transaction->run(function () use ($command): void {
            $request = $this->requests->byIdForUpdate($command->requestId);
            if (null === $request || $request->workerId() !== $command->ownerId || !$request->isOpen()) {
                throw new InvalidArgumentException('Esta solicitud ya no está disponible.');
            }
            $availability = $this->availabilities->byId($command->availabilityId);
            if (null === $availability || !$availability->isActive()
                || $availability->workerId() === $request->workerId()
                || $availability->swapPoolId() !== $request->swapPoolId()
                || !$availability->workDate()->equals($request->workDate())
                || $availability->shiftKind() !== $request->shiftKind()) {
                throw new InvalidArgumentException('Esta persona ya no está disponible para cubrir el turno.');
            }
            $group = $this->workspace->requireGroupOfAssignment($availability->workerId(), $availability->workerAssignmentId(), $request->swapPoolId());
            if ($group->assignmentId !== $availability->workerAssignmentId()) {
                throw new InvalidArgumentException('La disponibilidad ya no pertenece a un lugar de trabajo activo.');
            }
            $target = $this->rosteredDays->dayFor($availability->workerAssignmentId(), (string) $request->workDate());
            if ($target->isWorking()) {
                throw new InvalidArgumentException('La persona elegida ya trabaja ese día.');
            }
            $request->cover($command->ownerId, $availability->workerId(), $availability->workerAssignmentId(), $this->clock->now());
            $this->rosteredDays->transferCoverage($request->workerAssignmentId(), $availability->workerAssignmentId(), (string) $request->workDate());
            $this->requests->save($request);
        });
    }
}
