<?php

declare(strict_types=1);

namespace App\Coordination\Application\Command;

final readonly class RevokeScheduleLink
{
    public function __construct(public string $actorId)
    {
    }
}
