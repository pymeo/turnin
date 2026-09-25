<?php

declare(strict_types=1);

namespace App\Notification\Application\Event;

use App\Notification\Application\DeliverNotification;
use App\Notification\Domain\NotificationType;
use App\Swap\Domain\Event\SwapAgreementReached;

final readonly class NotifySwapAgreementReachedHandler
{
    public function __construct(private DeliverNotification $notifications)
    {
    }

    public function __invoke(SwapAgreementReached $event): void
    {
        $target = '/app/changes/agreements/'.$event->proposalId;
        $body = 'Habéis acordado el cambio del '.$this->shortDate($event->requestedDate).'.';
        if ($event->requiresApproval) {
            $body .= ' Pendiente de aprobación/registro del centro.';
        }
        foreach ([
            [$event->requestOwnerId, $event->proposerName],
            [$event->proposerId, $event->requestOwnerName],
        ] as [$recipient, $other]) {
            $this->notifications->deliver($event->eventId(), $recipient, NotificationType::SWAP_AGREEMENT, 'Cambio acordado con '.$other, $body, $target);
        }
        foreach ($event->approverIds as $approver) {
            $this->notifications->deliver($event->eventId(), $approver, NotificationType::SWAP_APPROVAL_REQUESTED, 'Cambio pendiente de revisar', $event->requestOwnerName.' y '.$event->proposerName.' han acordado un cambio para el '.$this->shortDate($event->requestedDate).'.', '/app/responsable');
        }
    }

    private function shortDate(string $date): string
    {
        $parts = explode('-', $date);

        return (string) ((int) ($parts[2] ?? 0));
    }
}
