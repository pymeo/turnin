<?php

declare(strict_types=1);

namespace App\Notification\Application\Command;

use App\Notification\Domain\NotificationIdGenerator;
use App\Notification\Domain\PushSubscription;
use App\Notification\Domain\PushSubscriptions;
use Psr\Clock\ClockInterface;

final readonly class RegisterPushSubscriptionHandler
{
    public function __construct(private PushSubscriptions $subscriptions, private NotificationIdGenerator $ids, private ClockInterface $clock)
    {
    }

    public function __invoke(RegisterPushSubscription $command): void
    {
        $this->subscriptions->save(new PushSubscription($this->ids->next(), $command->recipientId, $command->endpoint, $command->publicKey, $command->authToken, $this->clock->now()));
    }
}
