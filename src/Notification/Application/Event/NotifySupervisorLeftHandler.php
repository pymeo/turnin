<?php

declare(strict_types=1);

namespace App\Notification\Application\Event;

use App\Notification\Application\DeliverNotification;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\PendingApprovals;
use App\Workforce\Domain\Supervision\Event\SupervisorLeft;

/**
 * Only a verified supervisor stepping down is news for the team. It is pushed
 * only when it strands agreements that were waiting for approval.
 */
final readonly class NotifySupervisorLeftHandler
{
    public function __construct(private DeliverNotification $notifications, private PendingApprovals $pending)
    {
    }

    public function __invoke(SupervisorLeft $event): void
    {
        if (!$event->wasVerified) {
            return;
        }
        $body = $event->supervisorName.' ya no figura como responsable de '.$event->teamLabel.'.';
        if (!$event->poolStillSupervised) {
            $body .= ' Necesitáis verificar a otro responsable para aprobar nuevos cambios.';
        }
        $push = !$event->poolStillSupervised && $this->pending->countInPool($event->swapPoolId) > 0;
        foreach ($event->memberIds as $member) {
            $this->notifications->deliver(
                $event->eventId(),
                $member,
                NotificationType::SUPERVISOR_TEAM_UPDATE,
                $event->poolStillSupervised ? 'Cambio de responsable' : 'Vuestro equipo se ha quedado sin responsable verificado',
                $body,
                '/app/equipo',
                $push,
            );
        }
    }
}
