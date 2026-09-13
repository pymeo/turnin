<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\Swap\Domain\ExchangeBalance;
use App\Swap\Domain\ExchangeBalances;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapIdGenerator;
use App\Swap\Domain\SwapProposalKind;
use App\Swap\Domain\SwapProposals as ProposalRepository;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\SwapTransaction;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class DecideSwapProposalHandler
{
    public function __construct(private SwapTransaction $transaction, private ProposalRepository $proposals, private SwapRequests $requests, private RosteredDays $days, private ExchangeBalances $balances, private SwapIdGenerator $ids, private ClockInterface $clock)
    {
    }

    public function __invoke(DecideSwapProposal $command): void
    {
        $this->transaction->run(function () use ($command): void {
            $proposal = $this->proposals->byIdForUpdate($command->proposalId) ?? throw new InvalidArgumentException('La propuesta no existe.');
            $now = $this->clock->now();
            if ('withdraw' === $command->decision) {
                $proposal->withdraw($command->workerId, $now);
                $this->proposals->save($proposal);

                return;
            }
            if ('reject' === $command->decision) {
                $proposal->reject($command->workerId, $now);
                $this->proposals->save($proposal);

                return;
            }
            if ('accept' !== $command->decision) {
                throw new InvalidArgumentException('La decisión no es válida.');
            }
            $request = $this->requests->byIdForUpdate($proposal->requestId()) ?? throw new InvalidArgumentException('La solicitud ya no existe.');
            if (!$request->isOpen()) {
                throw new InvalidArgumentException('El turno ya ha sido resuelto.');
            }
            $proposal->accept($command->workerId, $now);
            if (SwapProposalKind::EXCHANGE !== $proposal->kind()) {
                $requested = $this->days->dayFor($request->workerAssignmentId(), (string) $request->workDate());
                if (!$requested->isWorking()) {
                    throw new InvalidArgumentException('El turno solicitado ya no existe.');
                }
                $this->days->transferCoverage($request->workerAssignmentId(), $proposal->proposerAssignmentId(), (string) $request->workDate());
                if (SwapProposalKind::DEFERRED === $proposal->kind()) {
                    $this->balances->save(ExchangeBalance::earn($this->ids->next(), $proposal->proposerId(), $request->workerId(), $request->id(), $request->rosterDayId(), $requested->durationMinutes(), $proposal->returnPreference(), $now));
                }
            } else {
                $offeredDate = $proposal->offeredWorkDate() ?? throw new InvalidArgumentException('La propuesta no tiene turno de vuelta.');
                $this->days->exchange($request->workerAssignmentId(), (string) $request->workDate(), $proposal->proposerAssignmentId(), (string) $offeredDate);
            }
            $request->cover($command->workerId, $proposal->proposerId(), $proposal->proposerAssignmentId(), $now);
            $this->requests->save($request);
            $this->proposals->save($proposal);
        });
    }
}
