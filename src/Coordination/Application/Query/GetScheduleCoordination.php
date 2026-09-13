<?php

declare(strict_types=1);

namespace App\Coordination\Application\Query;

use DateTimeImmutable;

final readonly class GetScheduleCoordination
{
    public function __construct(public string $viewerId, public DateTimeImmutable $from, public DateTimeImmutable $to)
    {
    }
}
