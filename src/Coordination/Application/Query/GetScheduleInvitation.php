<?php

declare(strict_types=1);

namespace App\Coordination\Application\Query;

final readonly class GetScheduleInvitation
{
    public function __construct(public string $plainToken)
    {
    }
}
