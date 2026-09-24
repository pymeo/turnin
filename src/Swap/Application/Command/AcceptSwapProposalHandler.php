<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\Swap\Application\ExecuteSwapAgreement;
use App\Swap\Domain\ShiftExchangeGovernance;
use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\SwapProposalStatus;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\SwapTransaction;
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
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AcceptSwapProposal $command): void
    {
        $this->transaction->run(function () use ($command): void {
            $proposal = $this->proposals->byIdForUpdate($command->proposalId) ?? throw new InvalidArgumentException('Esa propuesta ya no existe.');
            if ($proposal->requestOwnerId() !== $command->workerId) {
                throw new InvalidArgumentException('Solo quien publicó el turno puede aceptar.');
            }
            if (\in_array($proposal->status(), [SwapProposalStatus::PENDING_APPROVAL, SwapProposalStatus::EXECUTED], true)) {
                return;
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

            if ($this->governance->policyFor($request->swapPoolId())->requiresApproval) {
                $other = $this->proposals->pendingApprovalForRequest($request->id());
                if (null !== $other && $other->id() !== $proposal->id()) {
                    throw new InvalidArgumentException('Este turno ya tiene un acuerdo pendiente de aprobación.');
                }
                $proposal->awaitApproval($command->workerId, null, $now);
                $this->proposals->save($proposal);

                return;
            }

            $this->executor->execute($proposal, $request, $now);
        });
    }
}
