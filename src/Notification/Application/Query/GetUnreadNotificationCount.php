<?php

declare(strict_types=1);

namespace App\Notification\Application\Query;

final readonly class GetUnreadNotificationCount
{
    public function __construct(public string $recipientId)
    {
    }
}
