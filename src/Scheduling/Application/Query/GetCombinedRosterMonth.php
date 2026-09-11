<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

final readonly class GetCombinedRosterMonth
{
    public function __construct(public string $workerId, public ?string $month = null)
    {
    }
}
