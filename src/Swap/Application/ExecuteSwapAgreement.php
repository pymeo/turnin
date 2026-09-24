<?php

declare(strict_types=1);

namespace App\Swap\Application;

use App\Swap\Domain\ExchangeBalance;
use App\Swap\Domain\ExchangeBalances;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\RosteredShift;
use App\Swap\Domain\ShiftCompatibility;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapGroups;
use App\Swap\Domain\SwapIdGenerator;
use App\Swap\Domain\SwapProposal;
use App\Swap\Domain\SwapProposalKind;
use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\SwapRequests;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Where an agreement becomes two changed calendars.
 *
 * Everything is checked again here, inside the caller's transaction, and not
 * because the screens were careless: between proposing and accepting, either
 * rota can change, a membership can end, and another proposal can take the same
 * shift. The compatibility calculated when the options were sent is a snapshot,
 * never a permission.
 */
final readonly class ExecuteSwapAgreement
{
    public function __construct(
        private RosteredDays $days,
        private SwapGroups $groups,
        private ShiftCompatibilityResolver $compatibility,
        private ExchangeBalances $balances,
        private SwapRequests $requests,
        private SwapProposals $proposals,
        private SwapIdGenerator $ids,
    ) {
    }

    public function execute(SwapProposal $proposal, SwapRequest $request, DateTimeImmutable $now, ?string $approvedBy = null): void
    {
        // The calendar that receives the published shift is the one the chosen
        // option came from, which is not necessarily the proposal's own.
        $receivingAssignmentId = $proposal->proposerAssignmentId();
        if (SwapProposalKind::EXCHANGE === $proposal->kind()) {
            $option = $proposal->chosenOption() ?? throw new InvalidArgumentException('Elige uno de los turnos ofrecidos.');
            $receivingAssignmentId = $option->assignmentId;
            [$ownerCalendar, $proposerCalendar] = $this->revalidate($request, $proposal->proposerId(), $option->assignmentId, (string) $option->workDate, $option->rosterDayId);
            $this->days->exchange(
                $request->workerAssignmentId(),
                (string) $request->workDate(),
                $option->assignmentId,
                (string) $option->workDate,
                $ownerCalendar,
                $proposerCalendar,
            );
        } else {
            $requested = $this->days->dayFor($request->workerAssignmentId(), (string) $request->workDate());
            if (!$requested->isWorking()) {
                throw new InvalidArgumentException('El turno solicitado ya no existe.');
            }
            $this->days->transferCoverage($request->workerAssignmentId(), $proposal->proposerAssignmentId(), (string) $request->workDate());
            if (SwapProposalKind::DEFERRED === $proposal->kind() && null === $this->balances->bySourceRequest($request->id())) {
                $this->balances->save(ExchangeBalance::earn($this->ids->next(), $proposal->proposerId(), $request->workerId(), $request->id(), $request->rosterDayId(), $requested->durationMinutes(), $proposal->returnPreference(), $now));
            }
            if (SwapProposalKind::REDEMPTION === $proposal->kind()) {
                $balance = $this->balances->byIdForUpdate($proposal->exchangeBalanceId() ?? '') ?? throw new InvalidArgumentException('El saldo ya no existe.');
                $balance->redeemReserved($proposal->reservedMinutes(), $now);
                $this->balances->save($balance);
            }
        }

        $request->cover($request->workerId(), $proposal->proposerId(), $receivingAssignmentId, $now);
        $proposal->execute($request->workerId(), null, $now, $approvedBy);
        $this->requests->save($request);
        $this->proposals->save($proposal);

        // Nothing else can win this request any more, and leaving proposals
        // "pending" would promise a shift the roster has already moved.
        foreach ($this->proposals->liveForRequest($request->id()) as $other) {
            if ($other->id() === $proposal->id()) {
                continue;
            }
            $other->expire($now);
            $this->proposals->save($other);
        }
    }

    /**
     * Both halves of the trade, in both directions, against the roster as it is
     * now. Returns the current calendars: editing a labour profile must not
     * strand a still-valid agreement on an inactive assignment.
     *
     * @return array{string, string}
     */
    private function revalidate(SwapRequest $request, string $proposerId, string $optionAssignmentId, string $optionDate, string $optionRosterDayId): array
    {
        $ownerCalendar = $this->activeAssignmentInPool($request->workerId(), $request->swapPoolId());
        $proposerCalendar = $this->activeAssignmentInPool($proposerId, $request->swapPoolId());
        if (null === $ownerCalendar || null === $proposerCalendar) {
            throw new InvalidArgumentException('Este cambio ya no es posible porque ha cambiado el equipo.');
        }

        $requestedKey = RosteredDay::keyFor($request->workerAssignmentId(), (string) $request->workDate());
        $optionKey = RosteredDay::keyFor($optionAssignmentId, $optionDate);
        $shifts = $this->groupByDay($this->days->shiftsFor([
            [$request->workerAssignmentId(), (string) $request->workDate()],
            [$optionAssignmentId, $optionDate],
        ]));

        $requested = $shifts[$requestedKey] ?? throw new InvalidArgumentException('Este cambio ya no es posible porque el cuadrante ha cambiado.');
        $offered = $shifts[$optionKey] ?? throw new InvalidArgumentException('Este cambio ya no es posible porque el cuadrante ha cambiado.');
        if ($requested[0]->rosterDayId !== $request->rosterDayId() || $offered[0]->rosterDayId !== $optionRosterDayId) {
            throw new InvalidArgumentException('Este cambio ya no es posible porque el cuadrante ha cambiado.');
        }

        $this->requireCompatible($proposerId, $requested, $requestedKey, [$optionKey => true]);
        $this->requireCompatible($request->workerId(), $offered, $optionKey, [$requestedKey => true]);

        return [$ownerCalendar, $proposerCalendar];
    }

    private function activeAssignmentInPool(string $workerId, string $poolId): ?string
    {
        foreach ($this->groups->activeFor($workerId) as $group) {
            if ($group->poolId === $poolId) {
                return $group->assignmentId;
            }
        }

        return null;
    }

    /**
     * @param non-empty-list<RosteredShift> $target
     * @param array<string, true>           $releasing
     */
    private function requireCompatible(string $workerId, array $target, string $key, array $releasing): void
    {
        $assignments = array_values(array_unique(array_map(
            static fn (SwapGroup $group): string => $group->assignmentId,
            $this->groups->activeFor($workerId),
        )));
        $verdict = $this->compatibility->assess($assignments, $target, [$key => [] !== $assignments], $releasing)[$key] ?? ShiftCompatibility::allowed();
        if (!$verdict->compatible) {
            throw new InvalidArgumentException('Este cambio ya no es posible porque el cuadrante ha cambiado.');
        }
    }

    /**
     * @param list<RosteredShift> $shifts
     *
     * @return array<string, non-empty-list<RosteredShift>>
     */
    private function groupByDay(array $shifts): array
    {
        $byDay = [];
        foreach ($shifts as $shift) {
            $byDay[$shift->dayKey()][] = $shift;
        }

        return $byDay;
    }
}
