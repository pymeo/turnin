<?php

declare(strict_types=1);

namespace App\Notification\Application\Event;

use App\Notification\Application\DeliverNotification;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\PendingApprovals;
use App\Workforce\Domain\Supervision\Event\SupervisorVerified;

/**
 * The supervisor hears it by push; the team only in the bell. Agreements that
 * were already waiting before anybody was verified are counted now, so they
 * are not lost for having happened first.
 */
final readonly class NotifySupervisorVerifiedHandler
{
    public function __construct(private DeliverNotification $notifications, private PendingApprovals $pending)
    {
    }

    public function __invoke(SupervisorVerified $event): void
    {
        $this->notifications->deliver(
            $event->eventId(),
            $event->supervisorId,
            NotificationType::SUPERVISOR_VERIFIED,
            'Tu equipo te ha verificado como responsable',
            'El equipo de '.$event->teamLabel.' ha confirmado que eres su responsable. Ya puedes revisar sus cambios pendientes.',
            '/app/responsable',
        );
        $waiting = $this->pending->countInPool($event->swapPoolId);
        if ($waiting > 0) {
            $this->notifications->deliver(
                $event->eventId().':pending',
                $event->supervisorId,
                NotificationType::SUPERVISOR_PENDING_APPROVALS,
                1 === $waiting ? 'Tienes 1 cambio pendiente de revisar' : 'Tienes '.$waiting.' cambios pendientes de revisar',
                'Son cambios de '.$event->teamLabel.' acordados antes de tu verificación.',
                '/app/responsable',
                false,
            );
        }
        foreach ($event->memberIds as $member) {
            $this->notifications->deliver(
                $event->eventId(),
                $member,
                NotificationType::SUPERVISOR_TEAM_UPDATE,
                'Ya tenéis responsable en Turnin',
                'El equipo de '.$event->teamLabel.' ha verificado a '.$event->supervisorName.' como responsable.',
                '/app/equipo',
                false,
            );
        }
    }
}
