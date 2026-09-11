<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Identity;

use App\Platform\Identity\Domain\UsageIdentityIdGenerator;
use Symfony\Component\Uid\Uuid;

final class SymfonyUsageIdentityIdGenerator implements UsageIdentityIdGenerator
{
    public function next(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
