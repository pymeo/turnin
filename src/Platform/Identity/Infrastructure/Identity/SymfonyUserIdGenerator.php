<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Identity;

use App\Platform\Identity\Domain\UserId;
use App\Platform\Identity\Domain\UserIdGenerator;
use Symfony\Component\Uid\Uuid;

final class SymfonyUserIdGenerator implements UserIdGenerator
{
    public function next(): UserId
    {
        return new UserId(Uuid::v7()->toRfc4122());
    }
}
