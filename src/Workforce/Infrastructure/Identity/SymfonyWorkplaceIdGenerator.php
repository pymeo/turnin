<?php

declare(strict_types=1);

namespace App\Workforce\Infrastructure\Identity;

use App\Workforce\Domain\WorkplaceId;
use App\Workforce\Domain\WorkplaceIdGenerator;
use Symfony\Component\Uid\Uuid;

final readonly class SymfonyWorkplaceIdGenerator implements WorkplaceIdGenerator
{
    public function next(): WorkplaceId
    {
        return new WorkplaceId(Uuid::v7()->toRfc4122());
    }
}
