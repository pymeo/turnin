<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Identity;

use App\Scheduling\Domain\CalendarBlockIdGenerator;
use Symfony\Component\Uid\Uuid;

final readonly class SymfonyCalendarBlockIdGenerator implements CalendarBlockIdGenerator
{
    public function next(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
