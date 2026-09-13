<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class ExchangeBalanceView
{
    /** @param list<int> $preferredWeekdays */
    public function __construct(public string $id, public bool $inMyFavor, public string $otherName, public int $earnedMinutes, public int $redeemedMinutes, public int $remainingMinutes, public int $availableMinutes, public string $status, public string $sourceDate, public ?string $preferredMonth, public ?string $preferredKind, public ?int $preferredDuration, public array $preferredWeekdays)
    {
    }
}
