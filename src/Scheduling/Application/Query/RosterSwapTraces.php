<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

interface RosterSwapTraces
{
    /** @return list<RosterSwapTrace> */
    public function forWorkerInRange(string $workerId, string $from, string $to): array;
}
