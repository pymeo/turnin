<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Identity;

use App\Workforce\Domain\Supervision\SupervisionIdGenerator;
use Symfony\Component\Uid\Uuid;

final class SymfonySupervisionIdGenerator implements SupervisionIdGenerator
{
    public function next(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
