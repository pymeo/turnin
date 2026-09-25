<?php

declare(strict_types=1);

namespace App\Notification\Application\Event;

use App\Notification\Application\DeliverNotification;
use App\Notification\Domain\NotificationType;
use App\Workforce\Domain\Supervision\Event\SupervisorVerificationRequested;

/**
 * Asks the team to vouch for a candidate. Push, because nobody gets authority
 * until colleagues answer. The inviter already counted as the first
 * confirmation, so they only hear that the invitation was accepted.
 */
final readonly class NotifySupervisorVerificationRequestedHandler
{
    public function __construct(private DeliverNotification $notifications)
    {
    }

    public function __invoke(SupervisorVerificationRequested $event): void
    {
        $target = '/app/equipo/responsable/verificar/'.$event->verificationToken;
        foreach ($event->verifierIds as $recipient) {
            $this->notifications->deliver(
                $event->eventId(),
                $recipient,
                NotificationType::SUPERVISOR_VERIFICATION_REQUESTED,
                'Comprueba a tu responsable',
                $event->candidateName.' se ha registrado como responsable de '.$event->teamLabel.'. ¿Es la persona que normalmente gestiona vuestros cambios?',
                $target,
            );
        }
        if ($event->inviterId !== $event->candidateId && !\in_array($event->inviterId, $event->verifierIds, true)) {
            $this->notifications->deliver(
                $event->eventId(),
                $event->inviterId,
                NotificationType::SUPERVISOR_INVITATION_ANSWERED,
                $event->candidateName.' ha aceptado tu invitación',
                'Tu invitación cuenta como primera confirmación. Faltan las de otros compañeros de '.$event->teamLabel.'.',
                '/app/equipo',
                false,
            );
        }
    }
}
