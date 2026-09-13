<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\ReturnPreference;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapIdGenerator;
use App\Swap\Domain\SwapProposal;
use App\Swap\Domain\SwapProposalKind;
use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\WorkDate;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class CreateSwapProposalHandler
{
    public function __construct(private SwapWorkspace $workspace, private RosteredDays $days, private SwapProposals $proposals, private SwapIdGenerator $ids, private ClockInterface $clock)
    {
    }

    public function __invoke(CreateSwapProposal $command): string
    {
        $request = $this->workspace->requireVisibleRequest($command->workerId, $command->requestId);
        if (!$request->isOpen() || $request->workerId() === $command->workerId) {
            throw new InvalidArgumentException('Este turno ya no admite propuestas.');
        }
        $group = $this->workspace->requireGroup($command->workerId, $request->swapPoolId());
        if ($this->days->dayFor($group->assignmentId, (string) $request->workDate())->isWorking()) {
            throw new InvalidArgumentException('Ya tienes un turno que se solapa con el que quieres coger.');
        }

        $kind = SwapProposalKind::from($command->kind);
        $offered = null;
        $offeredDate = null;
        if (SwapProposalKind::EXCHANGE === $kind) {
            if (null === $command->offeredAssignmentId || null === $command->offeredDate) {
                throw new InvalidArgumentException('Selecciona uno de tus turnos reales.');
            }
            $this->workspace->requireGroupOfAssignment($command->workerId, $command->offeredAssignmentId, $request->swapPoolId());
            $offeredDate = WorkDate::fromString($command->offeredDate);
            $offered = $this->days->dayFor($command->offeredAssignmentId, (string) $offeredDate);
            if (!$offered->isWorking()) {
                throw new InvalidArgumentException('El turno ofrecido ya no existe en tu calendario.');
            }
            $group = $this->workspace->requireGroupOfAssignment($command->workerId, $command->offeredAssignmentId, $request->swapPoolId());
        }

        $preference = null;
        if (SwapProposalKind::DEFERRED === $kind) {
            $preference = new ReturnPreference($command->preferredMonth, null === $command->preferredShiftKind ? null : ShiftKind::from($command->preferredShiftKind), $command->preferredDurationMinutes, $command->preferredWeekdays);
        }
        $proposal = SwapProposal::propose($this->ids->next(), $request->id(), $request->workerId(), $command->workerId, $group->assignmentId, $kind, $offered?->rosterDayId, $offeredDate, $this->clock->now(), $preference);
        $this->proposals->save($proposal);

        return $proposal->id();
    }
}
