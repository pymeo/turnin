<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

final readonly class GetSupervisorInvitation
{
    public function __construct(public string $token, public ?string $userId)
    {
    }
}
