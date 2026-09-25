<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\Swap\Application\ExecuteSwapAgreement;
use App\Swap\Application\RecordSwapAgreement;
use App\Swap\Application\SwapNotificationOutcome;
use App\Swap\Domain\Event\SwapAgreementReached;
use App\Swap\Domain\ShiftExchangeGovernance;
use App\Swap\Domain\SwapEvents;
use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\SwapProposalStatus;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\SwapTransaction;
use App\Swap\Domain\WorkerDisplayNames;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * The second and last move of a direct exchange.
 *
 * Two colleagues could be answering the same request at the same moment, so the
 * request and the proposal are both locked for update and everything is checked
 * again inside the transaction. Whoever gets there second is told the truth
 * rather than left with half an exchange.
 */
final readonly class AcceptSwapProposalHandler
{
    public function __construct(
        private SwapTransaction $transaction,
        private SwapProposals $proposals,
        private SwapRequests $requests,
        private ShiftExchangeGovernance $governance,
        private ExecuteSwapAgreement $executor,
        private RecordSwapAgreement $agreements,
        private SwapEvents $events,
        private WorkerDisplayNames $names,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AcceptSwapProposal $command): void
    {
        $result = $this->transaction->run(function () use ($command): ?SwapNotificationOutcome {
            $proposal = $this->proposals->byIdForUpdate($command->proposalId) ?? throw new InvalidArgumentException('Esa propuesta ya no existe.');
            if ($proposal->requestOwnerId() !== $command->workerId) {
                throw new InvalidArgumentException('Solo quien publicó el turno puede aceptar.');
            }
            if (\in_array($proposal->status(), [SwapProposalStatus::PENDING_APPROVAL, SwapProposalStatus::EXECUTED], true)) {
                return null;
            }
            if (SwapProposalStatus::PENDING !== $proposal->status()) {
                throw new InvalidArgumentException('Esta propuesta ya no se puede aceptar.');
            }
            if (null === $proposal->optionById($command->optionId)) {
                throw new InvalidArgumentException('Ese turno ya no es una de las opciones ofrecidas.');
            }

            $request = $this->requests->byIdForUpdate($proposal->requestId()) ?? throw new InvalidArgumentException('La solicitud ya no existe.');
            if (!$request->isOpen()) {
                throw new InvalidArgumentException('Este turno ya se ha resuelto con otra propuesta.');
            }

            $now = $this->clock->now();
            $proposal->chooseOption($command->workerId, $command->optionId, $now);

            $requiresApproval = $this->governance->policyFor($request->swapPoolId())->requiresApproval;
            $this->agreements->record($proposal, $request, $now);
            if ($requiresApproval) {
                $other = $this->proposals->pendingApprovalForRequest($request->id());
                if (null !== $other && $other->id() !== $proposal->id()) {
                    throw new InvalidArgumentException('Este turno ya tiene un acuerdo pendiente de aprobación.');
                }
                $proposal->awaitApproval($command->workerId, null, $now);
                $this->proposals->save($proposal);

                return new SwapNotificationOutcome($proposal->id(), $proposal->requestOwnerId(), $proposal->proposerId(), (string) $request->workDate(), true, swapPoolId: $request->swapPoolId());
            }

            $this->executor->execute($proposal, $request, $now);

            return new SwapNotificationOutcome($proposal->id(), $proposal->requestOwnerId(), $proposal->proposerId(), (string) $request->workDate());
        });
        if (!$result instanceof SwapNotificationOutcome) {
            return;
        }
        $names = $this->names->forWorkers([$result->requestOwnerId, $result->proposerId]);
        $this->events->publishAfterCommit(new SwapAgreementReached(
            $result->proposalId,
            $result->requestOwnerId,
            $result->proposerId,
            $names[$result->requestOwnerId] ?? 'Un compañero',
            $names[$result->proposerId] ?? 'Un compañero',
            $result->requestedDate,
            $result->requiresApproval,
            $result->swapPoolId,
            // Whoever is verified when the agreement is reached. Agreements
            // reached before anybody was verified are picked up by the
            // supervisor dashboard, which reads pending approvals, not events.
            $result->requiresApproval ? $this->governance->approversOf($result->swapPoolId) : [],
        ));
    }
}
