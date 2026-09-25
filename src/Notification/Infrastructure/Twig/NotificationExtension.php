<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Twig;

use App\Notification\Domain\UserNotifications;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class NotificationExtension extends AbstractExtension
{
    public function __construct(private readonly UserNotifications $notifications, private readonly string $vapidPublicKey)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('notification_unread_count', $this->unreadCount(...)),
            new TwigFunction('push_public_key', fn (): string => $this->vapidPublicKey),
        ];
    }

    public function unreadCount(string $recipientId): int
    {
        return '' === $recipientId ? 0 : $this->notifications->unreadCount($recipientId);
    }
}
