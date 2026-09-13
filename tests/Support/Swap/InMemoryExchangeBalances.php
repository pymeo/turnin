<?php

declare(strict_types=1);

namespace App\Tests\Support\Swap;

use App\Swap\Domain\ExchangeBalance;
use App\Swap\Domain\ExchangeBalances;

final class InMemoryExchangeBalances implements ExchangeBalances
{
    /** @var array<string, ExchangeBalance> */
    private array $balances = [];

    /** @param list<ExchangeBalance> $seed */
    public function __construct(array $seed = [])
    {
        foreach ($seed as $balance) {
            $this->save($balance);
        }
    }

    public function save(ExchangeBalance $balance): void
    {
        $this->balances[$balance->id()] = $balance;
    }

    public function byId(string $id): ?ExchangeBalance
    {
        return $this->balances[$id] ?? null;
    }

    public function byIdForUpdate(string $id): ?ExchangeBalance
    {
        return $this->byId($id);
    }

    public function bySourceRequest(string $requestId): ?ExchangeBalance
    {
        foreach ($this->balances as $balance) {
            if ($balance->sourceRequestId() === $requestId) {
                return $balance;
            }
        }

        return null;
    }

    public function involving(string $workerId): array
    {
        return array_values(array_filter(
            $this->balances,
            static fn (ExchangeBalance $balance): bool => $balance->creditorWorkerId() === $workerId || $balance->owingWorkerId() === $workerId,
        ));
    }

    public function openBetween(string $creditorWorkerId, string $owingWorkerId): array
    {
        return array_values(array_filter(
            $this->balances,
            static fn (ExchangeBalance $balance): bool => $balance->creditorWorkerId() === $creditorWorkerId
                && $balance->owingWorkerId() === $owingWorkerId
                && $balance->remainingMinutes() > 0,
        ));
    }
}
