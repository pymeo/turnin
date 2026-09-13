<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class BalanceOptionView
{
    /** @param list<string> $reasons */
    public function __construct(public string $requestId, public string $dateHeadline, public string $hours, public string $duration, public int $durationMinutes, public string $shiftLabel, public string $groupLabel, public int $remainingAfterMinutes, public int $score, public array $reasons)
    {
    }
}
