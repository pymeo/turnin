<?php

declare(strict_types=1);

namespace App\Notification\Application\Command;

final readonly class MarkAllNotificationsRead
{
    public function __construct(public string $recipientId)
    {
    }
}
