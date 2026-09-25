<?php

declare(strict_types=1);

namespace App\Notification\Application\Command;

use App\Notification\Domain\UserNotifications;
use Psr\Clock\ClockInterface;

final readonly class MarkAllNotificationsReadHandler
{
    public function __construct(private UserNotifications $notifications, private ClockInterface $clock)
    {
    }

    public function __invoke(MarkAllNotificationsRead $command): void
    {
        $this->notifications->markAllRead($command->recipientId, $this->clock->now());
    }
}
