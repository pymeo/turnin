<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Command;

final readonly class CalendarBlockCreated
{
    public function __construct(public string $id)
    {
    }
}
