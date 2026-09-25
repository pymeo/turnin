<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\Swap\Application\ExecuteSwapAgreement;
use App\Swap\Application\SwapNotificationOutcome;
use App\Swap\Domain\Event\SwapAgreementApproved;
use App\Swap\Domain\Event\SwapAgreementRejected;
use App\Swap\Domain\ExchangeBalances;
use App\Swap\Domain\ShiftExchangeGovernance;
use App\Swap\Domain\SwapEvents;
use App\Swap\Domain\SwapProposalKind;
use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\SwapProposalStatus;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\SwapTransaction;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class ReviewSwapApprovalHandler
{
    public function __construct(private SwapTransaction $transaction, private SwapProposals $proposals, private SwapRequests $requests, private ExchangeBalances $balances, private ShiftExchangeGovernance $governance, private ExecuteSwapAgreement $executor, private SwapEvents $events, private ClockInterface $clock)
    {
    }

    public function __invoke(ReviewSwapApproval $command): void
    {
        $result = $this->transaction->run(function () use ($command): ?SwapNotificationOutcome {
            $proposal = $this->proposals->byIdForUpdate($command->proposalId) ?? throw new InvalidArgumentException('La propuesta no existe.');
            if ('approve' === $command->decision && SwapProposalStatus::EXECUTED === $proposal->status()) {
                return null;
            }
            if ('reject' === $command->decision && SwapProposalStatus::APPROVAL_REJECTED === $proposal->status()) {
                return null;
            }
            if (SwapProposalStatus::PENDING_APPROVAL !== $proposal->status()) {
                throw new InvalidArgumentException('Este cambio ya no espera aprobación.');
            }
            $request = $this->requests->byIdForUpdate($proposal->requestId()) ?? throw new InvalidArgumentException('La solicitud ya no existe.');
            if (!$this->governance->canApprove($command->supervisorUserId, $request->swapPoolId())) {
                throw new InvalidArgumentException('No puedes aprobar cambios de este equipo.');
            }
            $now = $this->clock->now();
            if ('reject' === $command->decision) {
                if (SwapProposalKind::REDEMPTION === $proposal->kind()) {
                    $balance = $this->balances->byIdForUpdate($proposal->exchangeBalanceId() ?? '') ?? throw new InvalidArgumentException('El saldo ya no existe.');
                    $balance->releaseReservation($proposal->reservedMinutes(), $now);
                    $this->balances->save($balance);
                }
                $proposal->rejectApproval($now);
                $this->proposals->save($proposal);

                return new SwapNotificationOutcome($proposal->id(), $proposal->requestOwnerId(), $proposal->proposerId(), (string) $request->workDate());
            }
            if ('approve' !== $command->decision) {
                throw new InvalidArgumentException('La decisión no es válida.');
            }
            $this->executor->execute($proposal, $request, $now, $command->supervisorUserId);

            return new SwapNotificationOutcome($proposal->id(), $proposal->requestOwnerId(), $proposal->proposerId(), (string) $request->workDate(), true);
        });
        if (!$result instanceof SwapNotificationOutcome) {
            return;
        }
        $event = $result->requiresApproval
            ? new SwapAgreementApproved($result->proposalId, $result->requestOwnerId, $result->proposerId, $result->requestedDate)
            : new SwapAgreementRejected($result->proposalId, $result->requestOwnerId, $result->proposerId, $result->requestedDate);
        $this->events->publishAfterCommit($event);
    }
}
