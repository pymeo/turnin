<?php

declare(strict_types=1);

namespace App\Notification\Application\Query;

final readonly class NotificationView
{
    public function __construct(public string $id, public string $title, public string $body, public string $targetUrl, public bool $read, public string $createdAt)
    {
    }
}
