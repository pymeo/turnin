<?php

declare(strict_types=1);

namespace App\Notification\Domain;

interface AuthenticatedNotificationRecipient
{
    public function idForEmail(string $email): ?string;
}
