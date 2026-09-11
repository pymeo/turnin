<?php

declare(strict_types=1);

namespace App\Scheduling\Infrastructure\Identity;

use App\Scheduling\Domain\RosterIdGenerator;
use Symfony\Component\Uid\Uuid;

final readonly class SymfonyRosterIdGenerator implements RosterIdGenerator
{
    public function next(): string
    {
        return (string) Uuid::v7();
    }
}
