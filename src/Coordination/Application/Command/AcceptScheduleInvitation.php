<?php

declare(strict_types=1);

namespace App\Coordination\Application\Command;

final readonly class AcceptScheduleInvitation
{
    public function __construct(public string $guestId, public string $plainToken)
    {
    }
}
