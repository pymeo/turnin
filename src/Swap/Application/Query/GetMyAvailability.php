<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

final readonly class GetMyAvailability
{
    public function __construct(public string $workerId)
    {
    }
}
