<?php

declare(strict_types=1);

namespace App\Swap\Domain;

interface ExchangeBalances
{
    public function save(ExchangeBalance $balance): void;

    public function byIdForUpdate(string $id): ?ExchangeBalance;

    public function byId(string $id): ?ExchangeBalance;

    public function bySourceRequest(string $requestId): ?ExchangeBalance;

    /** @return list<ExchangeBalance> */
    public function involving(string $workerId): array;

    /** @return list<ExchangeBalance> */
    public function openBetween(string $creditorWorkerId, string $owingWorkerId): array;
}
