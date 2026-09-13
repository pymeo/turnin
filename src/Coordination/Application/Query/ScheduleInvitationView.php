<?php

declare(strict_types=1);

namespace App\Coordination\Application\Query;

use DateTimeImmutable;

final readonly class ScheduleInvitationView
{
    public function __construct(public string $inviterName, public DateTimeImmutable $expiresAt, public bool $available)
    {
    }
}
