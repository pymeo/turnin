<?php

declare(strict_types=1);

namespace App\Notification\Application\Query;

use App\Notification\Domain\UserNotifications;

final readonly class GetUnreadNotificationCountHandler
{
    public function __construct(private UserNotifications $notifications)
    {
    }

    public function __invoke(GetUnreadNotificationCount $query): int
    {
        return $this->notifications->unreadCount($query->recipientId);
    }
}
