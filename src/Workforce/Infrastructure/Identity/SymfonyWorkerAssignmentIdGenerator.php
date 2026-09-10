<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Identity;

use App\Workforce\Domain\WorkerAssignmentIdGenerator;
use Symfony\Component\Uid\Uuid;

final class SymfonyWorkerAssignmentIdGenerator implements WorkerAssignmentIdGenerator
{
    public function next(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
