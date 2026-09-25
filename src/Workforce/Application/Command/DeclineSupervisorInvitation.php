<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

final readonly class DeclineSupervisorInvitation
{
    public function __construct(public string $userId, public string $token)
    {
    }
}
