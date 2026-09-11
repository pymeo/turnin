<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

final readonly class RosterCalendarExported
{
    public function __construct(public int $created, public int $updated)
    {
    }
}
