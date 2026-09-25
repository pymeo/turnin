<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Domain\Supervision\Event\SupervisorVerificationRequested;
use App\Workforce\Domain\Supervision\SupervisionEvents;
use App\Workforce\Domain\Supervision\SupervisionIdGenerator;
use App\Workforce\Domain\Supervision\SupervisorAssignment;
use App\Workforce\Domain\Supervision\SupervisorAssignments;
use App\Workforce\Domain\Supervision\SupervisorLinkTokens;
use App\Workforce\Domain\Supervision\SupervisorProfiles;
use App\Workforce\Domain\Supervision\SwapPoolTeams;
use Psr\Clock\ClockInterface;

/**
 * The second way into the same SupervisorAssignment: a member of the pool
 * asks the team to confirm them. No invitation is created, because nobody
 * invited anybody, and therefore nobody has vouched yet — it starts at zero.
 * From here on it is the invitation flow exactly: same verification link,
 * same policy, same event, same authority check.
 *
 * Runs in the command bus transaction, serialised per person and pool.
 */
final readonly class RequestSupervisionHandler
{
    public function __construct(
        private SupervisorAssignments $assignments,
        private SwapPoolTeams $teams,
        private SupervisorLinkTokens $tokens,
        private SupervisorProfiles $profiles,
        private SupervisionIdGenerator $ids,
        private SupervisionEvents $events,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RequestSupervision $command): SupervisionRequested
    {
        $team = $this->teams->team($command->swapPoolId);
        $existing = $this->assignments->activeForUpdate($command->workerId, $command->swapPoolId);
        if (null !== $existing) {
            return new SupervisionRequested($existing->id(), $existing->hasApprovalAuthority() ? SupervisionRequested::ALREADY_VERIFIED : SupervisionRequested::ALREADY_PENDING);
        }

        $id = $this->ids->next();
        $verificationToken = $this->tokens->verificationToken($id);
        $assignment = SupervisorAssignment::selfRequested($id, $command->workerId, $team, $this->tokens->hash($verificationToken), $this->clock->now());
        $this->assignments->save($assignment);
        $this->profiles->markSupervisorProfile($command->workerId);

        $this->events->publishAfterCommit(new SupervisorVerificationRequested(
            $id,
            $command->workerId,
            $this->profiles->displayName($command->workerId),
            null,
            $this->teams->describe($command->swapPoolId)->teamLabel ?? 'tu equipo',
            $verificationToken,
            $team->membersExcept($command->workerId),
        ));

        return new SupervisionRequested($id, SupervisionRequested::CREATED);
    }
}
