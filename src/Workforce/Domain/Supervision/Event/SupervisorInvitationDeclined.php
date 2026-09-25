<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision\Event;

final readonly class SupervisorInvitationDeclined implements SupervisionEvent
{
    public function __construct(public string $invitationId, public string $inviterId, public string $candidateName, public string $teamLabel)
    {
    }

    public function eventId(): string
    {
        return 'supervisor-invitation:'.$this->invitationId.':declined';
    }
}
