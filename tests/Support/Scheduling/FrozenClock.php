<?php

declare(strict_types=1);

namespace App\Tests\Support\Scheduling;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final readonly class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $instant;

    public function __construct(string $instant)
    {
        $this->instant = new DateTimeImmutable($instant);
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}
