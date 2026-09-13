<?php

declare(strict_types=1);

namespace App\Coordination\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BusyInterval
{
    public function __construct(public DateTimeImmutable $startsAt, public DateTimeImmutable $endsAt, public bool $work, public string $label = 'Ocupado', public ?string $assignmentId = null, public ?string $date = null)
    {
        if ($endsAt <= $startsAt) {
            throw new InvalidArgumentException('A busy interval must end after it starts.');
        }
    }
}
