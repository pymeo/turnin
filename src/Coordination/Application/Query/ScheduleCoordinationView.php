<?php

declare(strict_types=1);

namespace App\Coordination\Application\Query;

use App\Coordination\Domain\ScheduleDayComparison;

final readonly class ScheduleCoordinationView
{
    /** @param list<ScheduleDayComparison> $days */
    public function __construct(public string $linkId, public string $partnerName, public array $days)
    {
    }
}
