<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Domain\Supervision\Event\SupervisorVerified;
use App\Workforce\Domain\Supervision\SupervisionEvents;
use App\Workforce\Domain\Supervision\SupervisionIdGenerator;
use App\Workforce\Domain\Supervision\SupervisionRejected;
use App\Workforce\Domain\Supervision\SupervisorAssignments;
use App\Workforce\Domain\Supervision\SupervisorLinkTokens;
use App\Workforce\Domain\Supervision\SupervisorProfiles;
use App\Workforce\Domain\Supervision\SupervisorVerification;
use App\Workforce\Domain\Supervision\SupervisorVerificationPolicy;
use App\Workforce\Domain\Supervision\SupervisorVerificationSource;
use App\Workforce\Domain\Supervision\SwapPoolTeams;
use Psr\Clock\ClockInterface;

/**
 * One colleague answers "is this our supervisor?".
 *
 * The link only identifies the request. Membership is re-read here, on the
 * server, from Workforce's own tables; the assignment row is locked so two
 * colleagues answering at the same instant are counted one after the other and
 * only the one that reaches quorum publishes SupervisorVerified.
 */
final readonly class VerifySupervisorHandler
{
    public function __construct(
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

    /** @return bool true when this answer verified the supervisor */
    public function __invoke(VerifySupervisor $command): bool
    {
        $assignment = $this->assignments->byVerificationTokenHashForUpdate($this->tokens->hash($command->token)) ?? throw new SupervisionRejected('Esta solicitud de responsable no existe.');
        $team = $this->teams->team($assignment->swapPoolId());
        $verified = $assignment->recordVerification(new SupervisorVerification($this->ids->next(), $command->workerId, $command->decision, SupervisorVerificationSource::TEAM_MEMBER, $this->clock->now()), $team, $this->policy);
        $this->assignments->save($assignment);
        if ($verified) {
            $this->events->publishAfterCommit(new SupervisorVerified(
                $assignment->id(),
                $assignment->supervisorUserId(),
                $this->profiles->displayName($assignment->supervisorUserId()),
                $assignment->swapPoolId(),
                $this->teams->describe($assignment->swapPoolId())->teamLabel ?? 'tu equipo',
                $team->membersExcept($assignment->supervisorUserId()),
            ));
        }

        return $verified;
    }
}
