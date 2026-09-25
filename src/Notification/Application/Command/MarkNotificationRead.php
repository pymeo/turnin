<?php

declare(strict_types=1);

namespace App\Notification\Application\Command;

final readonly class MarkNotificationRead
{
    public function __construct(public string $recipientId, public string $notificationId)
    {
    }
}
