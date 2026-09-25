<?php

declare(strict_types=1);

namespace App\Notification\Application\Event;

use App\Notification\Application\DeliverNotification;
use App\Notification\Domain\NotificationType;
use App\Workforce\Domain\Supervision\Event\SupervisorInvitationDeclined;

final readonly class NotifySupervisorInvitationDeclinedHandler
{
    public function __construct(private DeliverNotification $notifications)
    {
    }

    public function __invoke(SupervisorInvitationDeclined $event): void
    {
        $this->notifications->deliver(
            $event->eventId(),
            $event->inviterId,
            NotificationType::SUPERVISOR_INVITATION_ANSWERED,
            $event->candidateName.' ha rechazado la invitación',
            'No será responsable de '.$event->teamLabel.'. Puedes invitar a otra persona.',
            '/app/equipo',
            false,
        );
    }
}
