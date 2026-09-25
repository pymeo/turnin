<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

final readonly class GetSupervisorVerification
{
    public function __construct(public string $workerId, public string $token)
    {
    }
}
