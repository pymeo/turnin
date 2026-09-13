<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class CandidateView
{
    public function __construct(public string $availabilityId, public string $name, public string $groupLabel)
    {
    }
}
