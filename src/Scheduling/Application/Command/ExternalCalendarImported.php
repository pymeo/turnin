<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

final readonly class ExternalCalendarImported
{
    public function __construct(public int $workDays, public int $personalBlocks, public int $updatedBlocks)
    {
    }
}
