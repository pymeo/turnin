<?php

declare(strict_types=1);

namespace App\Notification\Application\Query;

use App\Notification\Domain\UserNotification;
use App\Notification\Domain\UserNotifications;
use DateTimeImmutable;

final readonly class GetNotificationsHandler
{
    public function __construct(private UserNotifications $notifications)
    {
    }

    /** @return list<NotificationView> */
    public function __invoke(GetNotifications $query): array
    {
        return array_map(static fn (UserNotification $notification): NotificationView => new NotificationView(
            $notification->id(),
            $notification->title(),
            $notification->body(),
            $notification->targetUrl(),
            null !== $notification->readAt(),
            $notification->createdAt()->format(DateTimeImmutable::ATOM),
        ), $this->notifications->recentFor($query->recipientId));
    }
}
