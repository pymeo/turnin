<?php

declare(strict_types=1);

namespace App\Notification\Domain;

interface PushGateway
{
    public function send(PushSubscription $subscription, string $title, string $body, string $targetUrl): PushDelivery;
}
