<?php

declare(strict_types=1);

namespace App\Notification\Domain;

interface PushSubscriptions
{
    public function save(PushSubscription $subscription): void;

    /** @return list<PushSubscription> */
    public function activeFor(string $recipientId): array;

    public function remove(string $id): void;
}
