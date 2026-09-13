<?php

declare(strict_types=1);

namespace App\Swap\Application\Command;

use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\ExchangeBalances;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapIdGenerator;
use App\Swap\Domain\SwapProposal;
use App\Swap\Domain\SwapProposals;
use App\Swap\Domain\SwapTransaction;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class RequestBalanceRedemptionHandler
{
    public function __construct(private SwapTransaction $transaction, private SwapWorkspace $workspace, private ExchangeBalances $balances, private SwapProposals $proposals, private RosteredDays $days, private SwapIdGenerator $ids, private ClockInterface $clock)
    {
    }

    public function __invoke(RequestBalanceRedemption $command): string
    {
        return $this->transaction->run(function () use ($command): string {
            $balance = $this->balances->byIdForUpdate($command->balanceId) ?? throw new InvalidArgumentException('El saldo no existe.');
            if ($balance->creditorWorkerId() !== $command->workerId) {
                throw new InvalidArgumentException('Este saldo no está a tu favor.');
            }
            $request = $this->workspace->requireVisibleRequest($command->workerId, $command->requestId);
            if (!$request->isOpen() || $request->workerId() !== $balance->owingWorkerId()) {
                throw new InvalidArgumentException('Este turno no puede utilizar ese saldo.');
            }
            $existing = $this->proposals->activeRedemption($balance->id(), $request->id(), $command->workerId);
            if (null !== $existing) {
                return $existing->id();
            }
            $group = $this->workspace->requireGroup($command->workerId, $request->swapPoolId());
            if ($this->days->dayFor($group->assignmentId, (string) $request->workDate())->isWorking()) {
                throw new InvalidArgumentException('Ya tienes un turno que se solapa con el que quieres coger.');
            }
            $day = $this->days->dayFor($request->workerAssignmentId(), (string) $request->workDate());
            if (!$day->isWorking()) {
                throw new InvalidArgumentException('El turno ya no existe.');
            }
            $minutes = $day->durationMinutes();
            if ($minutes > $balance->availableMinutes()) {
                throw new InvalidArgumentException(\sprintf('Tu saldo disponible es de %s h y este turno dura %s h.', self::hours($balance->availableMinutes()), self::hours($minutes)));
            }
            $balance->reserve($minutes, $this->clock->now());
            $proposal = SwapProposal::proposeRedemption($this->ids->next(), $request->id(), $request->workerId(), $command->workerId, $group->assignmentId, $balance->id(), $minutes, $this->clock->now());
            $this->balances->save($balance);
            $this->proposals->save($proposal);

            return $proposal->id();
        });
    }

    private static function hours(int $minutes): string
    {
        return 0 === $minutes % 60 ? (string) intdiv($minutes, 60) : number_format($minutes / 60, 1, ',', '');
    }
}
