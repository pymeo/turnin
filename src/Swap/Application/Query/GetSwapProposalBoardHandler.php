<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\RosteredShift;
use App\Swap\Domain\ShiftDuration;
use App\Swap\Domain\SwapProposal;
use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\SwapProposalStatus;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDateLabel;
use App\Swap\Domain\WorkerDisplayNames;

/**
 * Everything I have proposed or been proposed, in plain words.
 */
final readonly class GetSwapProposalBoardHandler
{
    public function __construct(private SwapProposals $proposals, private SwapRequests $requests, private RosteredDays $days, private WorkerDisplayNames $names)
    {
    }

    /** @return list<SwapProposalView> */
    public function __invoke(GetSwapProposalBoard $query): array
    {
        $proposals = $this->proposals->involving($query->workerId);
        if ([] === $proposals) {
            return [];
        }

        $requests = [];
        $workerIds = [];
        $pairs = [];
        foreach ($proposals as $proposal) {
            $request = $this->requests->byId($proposal->requestId());
            if (null === $request) {
                continue;
            }
            $requests[$proposal->id()] = $request;
            $workerIds[] = $proposal->requestOwnerId();
            $workerIds[] = $proposal->proposerId();
            $pairs[] = [$request->workerAssignmentId(), (string) $request->workDate()];
            $chosen = $proposal->chosenOption();
            if (null !== $chosen) {
                $pairs[] = [$chosen->assignmentId, (string) $chosen->workDate];
            }
        }
        if ([] === $pairs) {
            return [];
        }

        $shifts = [];
        foreach ($this->days->shiftsFor($pairs) as $shift) {
            $shifts[$shift->dayKey()][] = $shift;
        }
        $names = $this->names->forWorkers(array_values(array_unique($workerIds)));

        $views = [];
        foreach ($proposals as $proposal) {
            $request = $requests[$proposal->id()] ?? null;
            if (null === $request) {
                continue;
            }
            $requested = $shifts[RosteredDay::keyFor($request->workerAssignmentId(), (string) $request->workDate())] ?? null;
            if (null === $requested) {
                continue;
            }
            $views[] = $this->view($proposal, $request, $requested, $shifts, $names, $query->workerId);
        }

        return $views;
    }

    /**
     * @param non-empty-list<RosteredShift>                $requested
     * @param array<string, non-empty-list<RosteredShift>> $shifts
     * @param array<string, string>                        $names
     */
    private function view(SwapProposal $proposal, SwapRequest $request, array $requested, array $shifts, array $names, string $workerId): SwapProposalView
    {
        $incoming = $proposal->requestOwnerId() === $workerId;
        $otherId = $incoming ? $proposal->proposerId() : $proposal->requestOwnerId();
        $chosen = $proposal->chosenOption();
        $chosenShifts = null === $chosen ? null : ($shifts[RosteredDay::keyFor($chosen->assignmentId, (string) $chosen->workDate)] ?? null);

        return new SwapProposalView(
            $proposal->id(),
            $proposal->requestId(),
            $proposal->status()->value,
            $this->label($proposal->status(), $incoming),
            $incoming,
            $incoming && SwapProposalStatus::PENDING === $proposal->status() && $request->isOpen(),
            $names[$otherId] ?? 'Un compañero',
            WorkDateLabel::headline($request->workDate()),
            $this->hoursOf($requested),
            ShiftDuration::label($this->minutesOf($requested)),
            $requested[0]->label,
            \count($proposal->options()),
            null === $chosen ? null : WorkDateLabel::headline($chosen->workDate),
            null === $chosenShifts ? null : $this->hoursOf($chosenShifts),
        );
    }

    private function label(SwapProposalStatus $status, bool $incoming): string
    {
        return match ($status) {
            SwapProposalStatus::PENDING => $incoming ? 'Tienes que elegir un turno' : 'Esperando respuesta',
            SwapProposalStatus::PENDING_APPROVAL => 'Pendiente de aprobación',
            SwapProposalStatus::EXECUTED, SwapProposalStatus::ACCEPTED => 'Cambio confirmado',
            SwapProposalStatus::REJECTED => 'Rechazada',
            SwapProposalStatus::APPROVAL_REJECTED => 'No aprobada',
            SwapProposalStatus::WITHDRAWN => 'Retirada',
            SwapProposalStatus::EXPIRED => 'Ya no es posible',
        };
    }

    /** @param non-empty-list<RosteredShift> $shifts */
    private function hoursOf(array $shifts): string
    {
        return implode(' · ', array_map(static fn (RosteredShift $shift): string => $shift->hours(), $shifts));
    }

    /** @param non-empty-list<RosteredShift> $shifts */
    private function minutesOf(array $shifts): int
    {
        $minutes = 0;
        foreach ($shifts as $shift) {
            $minutes += $shift->durationMinutes();
        }

        return $minutes;
    }
}
