<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Domain\ExchangeBalances;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkerDisplayNames;

final readonly class GetExchangeBalancesHandler
{
    public function __construct(private ExchangeBalances $balances, private SwapRequests $requests, private WorkerDisplayNames $names)
    {
    }

    /** @return list<ExchangeBalanceView> */
    public function __invoke(GetExchangeBalances $query): array
    {
        $balances = $this->balances->involving($query->workerId);
        $otherIds = array_map(static fn ($balance): string => $balance->creditorWorkerId() === $query->workerId ? $balance->owingWorkerId() : $balance->creditorWorkerId(), $balances);
        $names = $this->names->forWorkers(array_values(array_unique($otherIds)));
        $views = [];
        foreach ($balances as $balance) {
            $inMyFavor = $balance->creditorWorkerId() === $query->workerId;
            $otherId = $inMyFavor ? $balance->owingWorkerId() : $balance->creditorWorkerId();
            $preference = $balance->preference();
            $request = $this->requests->byId($balance->sourceRequestId());
            $views[] = new ExchangeBalanceView($balance->id(), $inMyFavor, $names[$otherId] ?? 'Un compañero', $balance->earnedMinutes(), $balance->redeemedMinutes(), $balance->remainingMinutes(), $balance->availableMinutes(), $balance->status()->value, null === $request ? '' : (string) $request->workDate(), $preference?->month, $preference?->shiftKind?->value, $preference?->durationMinutes, null === $preference ? [] : $preference->preferredWeekdays);
        }

        return $views;
    }
}
