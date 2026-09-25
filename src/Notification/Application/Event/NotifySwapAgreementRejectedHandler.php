<?php

declare(strict_types=1);

namespace App\Notification\Application\Event;

use App\Notification\Application\DeliverNotification;
use App\Notification\Domain\NotificationType;
use App\Swap\Domain\Event\SwapAgreementRejected;

final readonly class NotifySwapAgreementRejectedHandler
{
    public function __construct(private DeliverNotification $notifications)
    {
    }

    public function __invoke(SwapAgreementRejected $event): void
    {
        foreach ([$event->requestOwnerId, $event->proposerId] as $recipient) {
            $this->notifications->deliver($event->eventId(), $recipient, NotificationType::SWAP_REJECTED, 'Cambio no aprobado', 'El responsable no ha aprobado este cambio.', '/app/changes/agreements/'.$event->proposalId);
        }
    }
}
