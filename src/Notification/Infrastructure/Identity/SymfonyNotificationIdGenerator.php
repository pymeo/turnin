<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Identity;

use App\Notification\Domain\NotificationIdGenerator;
use Symfony\Component\Uid\Uuid;

final readonly class SymfonyNotificationIdGenerator implements NotificationIdGenerator
{
    public function next(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
