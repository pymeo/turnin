<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\Swap\Application\ExecuteSwapAgreement;
use App\Swap\Domain\ExchangeBalances;
use App\Swap\Domain\ShiftExchangeGovernance;
use App\Swap\Domain\SwapProposal;
use App\Swap\Domain\SwapProposalKind;
use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\SwapProposalStatus;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\SwapTransaction;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class DecideSwapProposalHandler
{
    public function __construct(private SwapTransaction $transaction, private SwapProposals $proposals, private SwapRequests $requests, private ExchangeBalances $balances, private ShiftExchangeGovernance $governance, private ExecuteSwapAgreement $executor, private ClockInterface $clock)
    {
    }

    public function __invoke(DecideSwapProposal $command): void
    {
        $this->transaction->run(function () use ($command): void {
            $proposal = $this->proposals->byIdForUpdate($command->proposalId) ?? throw new InvalidArgumentException('La propuesta no existe.');
            $now = $this->clock->now();
            if ('withdraw' === $command->decision) {
                if (SwapProposalStatus::WITHDRAWN === $proposal->status()) {
                    return;
                }
                $proposal->withdraw($command->workerId, $now);
                $this->releaseReservation($proposal, $now);
                $this->proposals->save($proposal);

                return;
            }
            if ('reject' === $command->decision) {
                if (SwapProposalStatus::REJECTED === $proposal->status()) {
                    return;
                }
                $proposal->reject($command->workerId, $now);
                $this->releaseReservation($proposal, $now);
                $this->proposals->save($proposal);

                return;
            }
            if ('accept' !== $command->decision) {
                throw new InvalidArgumentException('La decisión no es válida.');
            }
            if (\in_array($proposal->status(), [SwapProposalStatus::PENDING_APPROVAL, SwapProposalStatus::EXECUTED, SwapProposalStatus::ACCEPTED], true)) {
                return;
            }
            $request = $this->requests->byIdForUpdate($proposal->requestId()) ?? throw new InvalidArgumentException('La solicitud ya no existe.');
            if (!$request->isOpen()) {
                throw new InvalidArgumentException('El turno ya ha sido resuelto.');
            }
            $policy = $this->governance->policyFor($request->swapPoolId());
            if (SwapProposalKind::COVERAGE === $proposal->kind() && !$policy->allowsCoverage) {
                throw new InvalidArgumentException('Este centro no permite coberturas sin devolución.');
            }
            if ($policy->requiresApproval) {
                $other = $this->proposals->pendingApprovalForRequest($request->id());
                if (null !== $other && $other->id() !== $proposal->id()) {
                    throw new InvalidArgumentException('Este turno ya tiene un acuerdo pendiente de aprobación.');
                }
                $proposal->awaitApproval($command->workerId, $now);
                $this->proposals->save($proposal);

                return;
            }
            $this->executor->execute($proposal, $request, $now);
        });
    }

    private function releaseReservation(SwapProposal $proposal, DateTimeImmutable $now): void
    {
        if (SwapProposalKind::REDEMPTION !== $proposal->kind()) {
            return;
        }
        $balance = $this->balances->byIdForUpdate($proposal->exchangeBalanceId() ?? '') ?? throw new InvalidArgumentException('El saldo ya no existe.');
        $balance->releaseReservation($proposal->reservedMinutes(), $now);
        $this->balances->save($balance);
    }
}
