<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class BalanceOptionsView
{
    /** @param list<BalanceOptionView> $options */
    public function __construct(public string $balanceId, public string $otherName, public int $availableMinutes, public array $options)
    {
    }
}
