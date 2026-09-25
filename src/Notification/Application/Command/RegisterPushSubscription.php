<?php

declare(strict_types=1);

namespace App\Notification\Application\Command;

final readonly class RegisterPushSubscription
{
    public function __construct(public string $recipientId, public string $endpoint, public string $publicKey, public string $authToken)
    {
    }
}
