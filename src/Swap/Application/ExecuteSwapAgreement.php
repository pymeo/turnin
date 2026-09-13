<?php

declare(strict_types=1);

namespace App\Swap\Application;

use App\Swap\Domain\ExchangeBalance;
use App\Swap\Domain\ExchangeBalances;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapIdGenerator;
use App\Swap\Domain\SwapProposal;
use App\Swap\Domain\SwapProposalKind;
use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\SwapRequests;
use DateTimeImmutable;
use InvalidArgumentException;

/** Executes an already-authorized agreement inside the caller's transaction. */
final readonly class ExecuteSwapAgreement
{
    public function __construct(private RosteredDays $days, private ExchangeBalances $balances, private SwapRequests $requests, private SwapProposals $proposals, private SwapIdGenerator $ids)
    {
    }

    public function execute(SwapProposal $proposal, SwapRequest $request, DateTimeImmutable $now, ?string $approvedBy = null): void
    {
        if (SwapProposalKind::EXCHANGE === $proposal->kind()) {
            $offeredDate = $proposal->offeredWorkDate() ?? throw new InvalidArgumentException('La propuesta no tiene turno de vuelta.');
            $this->days->exchange($request->workerAssignmentId(), (string) $request->workDate(), $proposal->proposerAssignmentId(), (string) $offeredDate);
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

        $request->cover($request->workerId(), $proposal->proposerId(), $proposal->proposerAssignmentId(), $now);
        $proposal->execute($request->workerId(), $now, $approvedBy);
        $this->requests->save($request);
        $this->proposals->save($proposal);
    }
}
