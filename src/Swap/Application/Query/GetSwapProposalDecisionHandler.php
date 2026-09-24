<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\ShiftCompatibilityResolver;
use App\Swap\Application\SwapAccessDenied;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\RosteredShift;
use App\Swap\Domain\ShiftCompatibility;
use App\Swap\Domain\ShiftDuration;
use App\Swap\Domain\ShiftObstacle;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapProposalOption;
use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\SwapProposalStatus;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDateLabel;
use App\Swap\Domain\WorkerDisplayNames;
use InvalidArgumentException;

/**
 * The answering screen, recomputed every time it is opened.
 *
 * The options were compatible when they were sent. Between then and now either
 * rota can have changed, so each one is judged again — and the same check runs
 * once more inside the transaction that accepts, because a screen is never a
 * lock.
 */
final readonly class GetSwapProposalDecisionHandler
{
    public function __construct(
        private SwapWorkspace $workspace,
        private SwapProposals $proposals,
        private SwapRequests $requests,
        private RosteredDays $days,
        private ShiftCompatibilityResolver $compatibility,
        private WorkerDisplayNames $names,
    ) {
    }

    public function __invoke(GetSwapProposalDecision $query): SwapProposalDecisionView
    {
        $proposal = $this->proposals->byId($query->proposalId) ?? throw new InvalidArgumentException('Esa propuesta ya no existe.');
        if (!\in_array($query->workerId, [$proposal->requestOwnerId(), $proposal->proposerId()], true)) {
            throw SwapAccessDenied::notYours();
        }
        $request = $this->requests->byId($proposal->requestId()) ?? throw new InvalidArgumentException('La solicitud ya no existe.');
        $group = $this->workspace->requireGroup($query->workerId, $request->swapPoolId());

        $requested = $this->days->shiftsFor([[$request->workerAssignmentId(), (string) $request->workDate()]]);
        if ([] === $requested) {
            throw new InvalidArgumentException('El turno de esta propuesta ya no existe.');
        }

        $incoming = $proposal->requestOwnerId() === $query->workerId;
        $otherId = $incoming ? $proposal->proposerId() : $proposal->requestOwnerId();
        $options = $proposal->options();
        $pairs = array_map(static fn (SwapProposalOption $option): array => [$option->assignmentId, (string) $option->workDate], $options);
        $shifts = [];
        foreach ($this->days->shiftsFor($pairs) as $shift) {
            $shifts[$shift->dayKey()][] = $shift;
        }

        // Only the person who has to do them needs the verdict; the proposer
        // already knows they are their own shifts.
        $requestedKey = RosteredDay::keyFor($request->workerAssignmentId(), (string) $request->workDate());
        $verdicts = [];
        if ($incoming && [] !== $shifts) {
            $mine = array_values(array_unique(array_map(
                static fn (SwapGroup $each): string => $each->assignmentId,
                $this->workspace->groupsFor($query->workerId),
            )));
            $verdicts = $this->compatibility->assess($mine, array_merge(...array_values($shifts)), array_fill_keys(array_keys($shifts), true), [$requestedKey => true]);
        }

        $optionViews = [];
        foreach ($options as $option) {
            $key = RosteredDay::keyFor($option->assignmentId, (string) $option->workDate);
            $day = $shifts[$key] ?? null;
            $verdict = null === $day ? ShiftCompatibility::blocked(ShiftObstacle::SHIFT_OVERLAP, 'Ese turno ya no existe en el cuadrante.') : ($verdicts[$key] ?? ShiftCompatibility::allowed());
            $optionViews[] = $this->optionView($option, $day, $verdict);
        }

        return new SwapProposalDecisionView(
            $proposal->id(),
            $this->names->forWorkers([$otherId])[$otherId] ?? 'Un compañero',
            $incoming,
            $proposal->status()->value,
            $incoming && SwapProposalStatus::PENDING === $proposal->status() && $request->isOpen(),
            WorkDateLabel::headline($request->workDate()),
            $this->hoursOf($requested),
            ShiftDuration::label($this->minutesOf($requested)),
            $requested[0]->label,
            $group->label(),
            $optionViews,
            $proposal->chosenOptionId(),
            SwapProposalStatus::PENDING_APPROVAL === $proposal->status(),
        );
    }

    /** @param non-empty-list<RosteredShift>|null $day */
    private function optionView(SwapProposalOption $option, ?array $day, ShiftCompatibility $verdict): SwapProposalOptionView
    {
        return new SwapProposalOptionView(
            $option->id,
            (string) $option->workDate,
            WorkDateLabel::headline($option->workDate),
            WorkDateLabel::compact($option->workDate),
            null === $day ? 'Turno' : $day[0]->label,
            null === $day ? '' : $this->hoursOf($day),
            null === $day ? '' : ShiftDuration::label($this->minutesOf($day)),
            null !== $day && $day[0]->endsNextDay,
            null === $day ? 'oncall' : $day[0]->shiftKind->tone(),
            $verdict->compatible,
            $verdict->explanation,
        );
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
