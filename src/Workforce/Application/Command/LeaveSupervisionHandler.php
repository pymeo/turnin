<?php

declare(strict_types=1);

namespace App\Workforce\Application\Command;

use App\Workforce\Domain\Supervision\Event\SupervisorLeft;
use App\Workforce\Domain\Supervision\SupervisionEvents;
use App\Workforce\Domain\Supervision\SupervisionRejected;
use App\Workforce\Domain\Supervision\SupervisorAssignment;
use App\Workforce\Domain\Supervision\SupervisorAssignments;
use App\Workforce\Domain\Supervision\SupervisorProfiles;
use App\Workforce\Domain\Supervision\SwapPoolTeams;
use Psr\Clock\ClockInterface;

/**
 * Stepping down. Authority ends in this transaction; the assignment row and
 * every approval that points at its person stay. Changes waiting for approval
 * are left exactly as they are: nobody approves them by default.
 */
final readonly class LeaveSupervisionHandler
{
    public function __construct(
        private SupervisorAssignments $assignments,
        private SwapPoolTeams $teams,
        private SupervisorProfiles $profiles,
        private SupervisionEvents $events,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(LeaveSupervision $command): void
    {
        $assignment = $this->assignments->byIdForUpdate($command->assignmentId);
        if (null === $assignment || $assignment->supervisorUserId() !== $command->userId) {
            throw new SupervisionRejected('Esta responsabilidad no existe.');
        }
        $wasVerified = $assignment->hasApprovalAuthority();
        if (!$assignment->leave($command->userId, $this->clock->now())) {
            return;
        }
        $this->assignments->save($assignment);

        $stillSupervised = [] !== array_filter($this->assignments->activeInPool($assignment->swapPoolId()), static fn (SupervisorAssignment $other): bool => $other->hasApprovalAuthority());
        $this->events->publishAfterCommit(new SupervisorLeft(
            $assignment->id(),
            $assignment->supervisorUserId(),
            $this->profiles->displayName($assignment->supervisorUserId()),
            $assignment->swapPoolId(),
            $this->teams->describe($assignment->swapPoolId())->teamLabel ?? 'tu equipo',
            $this->teams->team($assignment->swapPoolId())->membersExcept($assignment->supervisorUserId()),
            $wasVerified,
            $stillSupervised,
        ));
    }
}
