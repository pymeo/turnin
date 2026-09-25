<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

use App\Workforce\Domain\Supervision\SupervisorAssignments;
use App\Workforce\Domain\Supervision\SupervisorInvitations;
use App\Workforce\Domain\Supervision\SupervisorInvitationStatus;
use App\Workforce\Domain\Supervision\SupervisorLinkTokens;
use App\Workforce\Domain\Supervision\SwapPoolTeams;
use Psr\Clock\ClockInterface;

/** Null when the token matches nothing: the page says so without detail. */
final readonly class GetSupervisorInvitationHandler
{
    public function __construct(private SupervisorInvitations $invitations, private SupervisorAssignments $assignments, private SwapPoolTeams $teams, private SupervisorLinkTokens $tokens, private ClockInterface $clock)
    {
    }

    public function __invoke(GetSupervisorInvitation $query): ?SupervisorInvitationView
    {
        $invitation = $this->invitations->byTokenHash($this->tokens->hash($query->token));
        $pool = null === $invitation ? null : $this->teams->describe($invitation->swapPoolId());
        if (null === $invitation || null === $pool) {
            return null;
        }
        $now = $this->clock->now();
        $state = match (true) {
            $invitation->isOpen($now) => SupervisorInvitationView::OPEN,
            $invitation->isExpired($now) => SupervisorInvitationView::EXPIRED,
            SupervisorInvitationStatus::ACCEPTED === $invitation->status() => SupervisorInvitationView::ACCEPTED,
            default => SupervisorInvitationView::UNAVAILABLE,
        };

        return new SupervisorInvitationView(
            $state,
            $pool->workplaceName,
            $pool->teamLabel,
            null !== $query->userId && $query->userId === $invitation->invitedByWorkerId(),
            null !== $query->userId && null !== $this->assignments->activeFor($query->userId, $invitation->swapPoolId()),
            null !== $query->userId && $query->userId === $invitation->respondedByUserId() && SupervisorInvitationStatus::ACCEPTED === $invitation->status(),
        );
    }
}
