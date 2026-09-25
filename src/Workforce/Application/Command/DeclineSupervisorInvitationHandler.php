<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Domain\Supervision\Event\SupervisorInvitationDeclined;
use App\Workforce\Domain\Supervision\SupervisionEvents;
use App\Workforce\Domain\Supervision\SupervisionRejected;
use App\Workforce\Domain\Supervision\SupervisorInvitations;
use App\Workforce\Domain\Supervision\SupervisorLinkTokens;
use App\Workforce\Domain\Supervision\SupervisorProfiles;
use App\Workforce\Domain\Supervision\SwapPoolTeams;
use Psr\Clock\ClockInterface;

final readonly class DeclineSupervisorInvitationHandler
{
    public function __construct(
        private SupervisorInvitations $invitations,
        private SwapPoolTeams $teams,
        private SupervisorLinkTokens $tokens,
        private SupervisorProfiles $profiles,
        private SupervisionEvents $events,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DeclineSupervisorInvitation $command): void
    {
        $invitation = $this->invitations->byTokenHashForUpdate($this->tokens->hash($command->token)) ?? throw new SupervisionRejected('Esta invitación no existe o ya no está disponible.');
        if (!$invitation->decline($command->userId, $this->clock->now())) {
            return;
        }
        $this->invitations->save($invitation);
        $this->events->publishAfterCommit(new SupervisorInvitationDeclined(
            $invitation->id(),
            $invitation->invitedByWorkerId(),
            $this->profiles->displayName($command->userId),
            $this->teams->describe($invitation->swapPoolId())->teamLabel ?? 'vuestro equipo',
        ));
    }
}
