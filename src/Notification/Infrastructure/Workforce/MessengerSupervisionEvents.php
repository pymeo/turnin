<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Workforce;

use App\Workforce\Domain\Supervision\Event\SupervisionEvent;
use App\Workforce\Domain\Supervision\SupervisionEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

final readonly class MessengerSupervisionEvents implements SupervisionEvents
{
    public function __construct(private MessageBusInterface $eventBus)
    {
    }

    public function publishAfterCommit(SupervisionEvent $event): void
    {
        $this->eventBus->dispatch(new Envelope($event, [new DispatchAfterCurrentBusStamp()]));
    }
}
