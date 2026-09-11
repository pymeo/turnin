<?php

declare(strict_types=1);

namespace App\Scheduling\Application\ExternalCalendar;

final readonly class ExternalCalendar
{
    public function __construct(public string $id, public string $name, public bool $writable)
    {
    }
}
