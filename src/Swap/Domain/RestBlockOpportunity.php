<?php

declare(strict_types=1);

namespace App\Swap\Domain;

final readonly class RestBlockOpportunity
{
    /** @param list<string> $reasons */
    public function __construct(public RosteredDay $shiftToRelease, public int $currentConsecutiveRestDays, public int $resultingConsecutiveRestDays, public string $restStartsAt, public string $restEndsAt, public int $gainedRestDays, public int $score, public array $reasons)
    {
    }
}
