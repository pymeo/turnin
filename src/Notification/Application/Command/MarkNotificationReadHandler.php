<?php

declare(strict_types=1);

namespace App\Notification\Application\Command;

use App\Notification\Domain\UserNotifications;
use Psr\Clock\ClockInterface;

final readonly class MarkNotificationReadHandler
{
    public function __construct(private UserNotifications $notifications, private ClockInterface $clock)
    {
    }

    public function __invoke(MarkNotificationRead $command): void
    {
        $notification = $this->notifications->byIdForRecipient($command->notificationId, $command->recipientId);
        if (null === $notification) {
            return;
        }
        $notification->markRead($this->clock->now());
        $this->notifications->save($notification);
    }
}
