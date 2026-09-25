<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class GetSupervisorDashboard
{
    public function __construct(public string $supervisorUserId)
    {
    }
}
