<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class GetMonthExchangeMarks
{
    /** @param string $month YYYY-MM */
    public function __construct(public string $workerId, public string $workerAssignmentId, public string $month)
    {
    }
}
