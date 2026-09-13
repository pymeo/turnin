<?php

declare(strict_types=1);

namespace App\Coordination\Application\Command;

use DateTimeImmutable;

final readonly class ScheduleInvitationCreated
{
    public function __construct(public string $token, public DateTimeImmutable $expiresAt)
    {
    }
}
