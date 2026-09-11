<?php

declare(strict_types=1);

namespace App\Scheduling\Application;

use App\Scheduling\Domain\RosterPattern;
use App\Scheduling\Domain\ScheduleDraft;
use App\Scheduling\Domain\WorkDate;

final readonly class ExpandedRosterPattern
{
    public function __construct(public RosterPattern $pattern, public ScheduleDraft $draft, public WorkDate $from, public WorkDate $to)
    {
    }
}
