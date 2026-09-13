<?php

declare(strict_types=1);

namespace App\Coordination\Application\Command;

final readonly class CreateScheduleInvitation
{
    public function __construct(public string $inviterId)
    {
    }
}
