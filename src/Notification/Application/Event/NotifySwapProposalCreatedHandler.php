<?php

declare(strict_types=1);

namespace App\Notification\Application\Event;

use App\Notification\Application\DeliverNotification;
use App\Notification\Domain\NotificationType;
use App\Swap\Domain\Event\SwapProposalCreated;

final readonly class NotifySwapProposalCreatedHandler
{
    public function __construct(private DeliverNotification $notifications)
    {
    }

    public function __invoke(SwapProposalCreated $event): void
    {
        $count = $event->optionCount;
        $this->notifications->deliver(
            $event->eventId(),
            $event->recipientId,
            NotificationType::SWAP_PROPOSAL,
            $event->proposerName.' te propone un cambio',
            \sprintf('Te ha enviado %d %s a cambio.', $count, 1 === $count ? 'turno posible' : 'turnos posibles'),
            '/app/changes/proposals/'.$event->proposalId,
        );
    }
}
