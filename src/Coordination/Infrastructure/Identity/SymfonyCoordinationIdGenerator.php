<?php

declare(strict_types=1);

namespace App\Coordination\Infrastructure\Identity;

use App\Coordination\Domain\CoordinationIdGenerator;
use Symfony\Component\Uid\Uuid;

final class SymfonyCoordinationIdGenerator implements CoordinationIdGenerator
{
    public function next(): string
    {
        return (string) Uuid::v7();
    }
}
