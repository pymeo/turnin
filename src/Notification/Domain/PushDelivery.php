<?php

declare(strict_types=1);

namespace App\Notification\Domain;

final readonly class PushDelivery
{
    public function __construct(public bool $delivered, public bool $subscriptionExpired = false)
    {
    }
}
