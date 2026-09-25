<?php

declare(strict_types=1);

namespace App\Notification\Application\Event;

use App\Notification\Application\DeliverNotification;
use App\Notification\Domain\NotificationType;
use App\Swap\Domain\Event\SwapAgreementApproved;

final readonly class NotifySwapAgreementApprovedHandler
{
    public function __construct(private DeliverNotification $notifications)
    {
    }

    public function __invoke(SwapAgreementApproved $event): void
    {
        foreach ([$event->requestOwnerId, $event->proposerId] as $recipient) {
            $this->notifications->deliver($event->eventId(), $recipient, NotificationType::SWAP_APPROVED, 'Cambio aprobado', 'El cambio del '.((int) substr($event->requestedDate, -2)).' ya está aprobado.', '/app/changes/agreements/'.$event->proposalId);
        }
    }
}
