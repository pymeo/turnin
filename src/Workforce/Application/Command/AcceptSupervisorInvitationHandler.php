<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Domain\Supervision\Event\SupervisorVerificationRequested;
use App\Workforce\Domain\Supervision\SupervisionEvents;
use App\Workforce\Domain\Supervision\SupervisionIdGenerator;
use App\Workforce\Domain\Supervision\SupervisionRejected;
use App\Workforce\Domain\Supervision\SupervisorAssignment;
use App\Workforce\Domain\Supervision\SupervisorAssignments;
use App\Workforce\Domain\Supervision\SupervisorInvitations;
use App\Workforce\Domain\Supervision\SupervisorLinkTokens;
use App\Workforce\Domain\Supervision\SupervisorProfiles;
use App\Workforce\Domain\Supervision\SupervisorVerification;
use App\Workforce\Domain\Supervision\SupervisorVerificationDecision;
use App\Workforce\Domain\Supervision\SupervisorVerificationPolicy;
use App\Workforce\Domain\Supervision\SupervisorVerificationSource;
use App\Workforce\Domain\Supervision\SwapPoolTeams;
use Psr\Clock\ClockInterface;

/**
 * Accepting means "I am the person who validates this team's changes".
 *
 * Creates a PENDING_VERIFICATION assignment and nothing more: no approval, no
 * roster. The colleague who created the invitation already vouched for this
 * person by sending it, so that is recorded as the first confirmation — as an
 * explicit, auditable row, and only while they are still in the team.
 *
 * Runs inside the command bus transaction; the invitation row is locked, so a
 * double tap waits for the first one and then finds it already accepted.
 */
final readonly class AcceptSupervisorInvitationHandler
{
    public function __construct(
        private SupervisorInvitations $invitations,
        private SupervisorAssignments $assignments,
        private SwapPoolTeams $teams,
        private SupervisorLinkTokens $tokens,
        private SupervisorProfiles $profiles,
        private SupervisorVerificationPolicy $policy,
        private SupervisionIdGenerator $ids,
        private SupervisionEvents $events,
        private ClockInterface $clock,
    ) {
    }

    /** @return string the assignment id */
    public function __invoke(AcceptSupervisorInvitation $command): string
    {
        $invitation = $this->invitations->byTokenHashForUpdate($this->tokens->hash($command->token)) ?? throw new SupervisionRejected('Esta invitación no existe o ya no está disponible.');
        $existing = $this->assignments->activeFor($command->userId, $invitation->swapPoolId());
        $now = $this->clock->now();
        if (!$invitation->accept($command->userId, $now)) {
            return $existing?->id() ?? throw new SupervisionRejected('Esta invitación ya no está disponible.');
        }
        if (null !== $existing) {
            throw new SupervisionRejected('Ya eres responsable de este equipo o estás pendiente de verificación.');
        }

        $id = $this->ids->next();
        $verificationToken = $this->tokens->verificationToken($id);
        $assignment = SupervisorAssignment::requestVerification($id, $command->userId, $invitation->swapPoolId(), $invitation->id(), $this->tokens->hash($verificationToken), $now);
        $team = $this->teams->team($invitation->swapPoolId());
        if ($team->includes($invitation->invitedByWorkerId())) {
            $assignment->recordVerification(new SupervisorVerification($this->ids->next(), $invitation->invitedByWorkerId(), SupervisorVerificationDecision::CONFIRMED, SupervisorVerificationSource::INVITATION, $now), $team, $this->policy);
        }
        $this->invitations->save($invitation);
        $this->assignments->save($assignment);
        $this->profiles->markSupervisorProfile($command->userId);

        $this->events->publishAfterCommit(new SupervisorVerificationRequested(
            $id,
            $command->userId,
            $this->profiles->displayName($command->userId),
            $invitation->invitedByWorkerId(),
            $this->teams->describe($invitation->swapPoolId())->teamLabel ?? 'tu equipo',
            $verificationToken,
            array_values(array_filter($team->membersExcept($command->userId), static fn (string $member): bool => !$assignment->hasAnswered($member))),
        ));

        return $id;
    }
}
