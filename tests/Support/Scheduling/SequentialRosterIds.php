<?php

declare(strict_types=1);

namespace App\Tests\Support\Scheduling;

use App\Scheduling\Domain\RosterIdGenerator;

final class SequentialRosterIds implements RosterIdGenerator
{
    private int $next = 0;

    public function next(): string
    {
        return \sprintf('id-%04d', ++$this->next);
    }
}
