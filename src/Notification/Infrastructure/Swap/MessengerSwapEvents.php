<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Swap;

use App\Swap\Domain\Event\SwapEvent;
use App\Swap\Domain\SwapEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

final readonly class MessengerSwapEvents implements SwapEvents
{
    public function __construct(private MessageBusInterface $eventBus)
    {
    }

    public function publishAfterCommit(SwapEvent $event): void
    {
        $this->eventBus->dispatch(new Envelope($event, [new DispatchAfterCurrentBusStamp()]));
    }
}
