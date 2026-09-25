<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

use App\Workforce\Domain\Supervision\SupervisorAssignments;
use App\Workforce\Domain\Supervision\SupervisorAssignmentStatus;
use App\Workforce\Domain\Supervision\SupervisorLinkTokens;
use App\Workforce\Domain\Supervision\SupervisorProfiles;
use App\Workforce\Domain\Supervision\SupervisorVerificationPolicy;
use App\Workforce\Domain\Supervision\SwapPoolTeams;

final readonly class GetSupervisorVerificationHandler
{
    public function __construct(private SupervisorAssignments $assignments, private SwapPoolTeams $teams, private SupervisorLinkTokens $tokens, private SupervisorProfiles $profiles, private SupervisorVerificationPolicy $policy)
    {
    }

    public function __invoke(GetSupervisorVerification $query): SupervisorVerificationView
    {
        $assignment = $this->assignments->byVerificationTokenHash($this->tokens->hash($query->token));
        if (null === $assignment) {
            return new SupervisorVerificationView(SupervisorVerificationView::FORBIDDEN);
        }
        $team = $this->teams->team($assignment->swapPoolId());
        $isCandidate = $assignment->supervisorUserId() === $query->workerId;
        if (!$isCandidate && !$team->includes($query->workerId)) {
            return new SupervisorVerificationView(SupervisorVerificationView::FORBIDDEN);
        }
        if (!$assignment->status()->isActive()) {
            return new SupervisorVerificationView(SupervisorVerificationView::INACTIVE);
        }
        $pool = $this->teams->describe($assignment->swapPoolId());
        $mine = null;
        foreach ($assignment->verifications() as $verification) {
            if ($verification->verifierWorkerId === $query->workerId) {
                $mine = $verification->decision->value;
            }
        }
        $state = match (true) {
            $isCandidate => SupervisorVerificationView::CANDIDATE,
            SupervisorAssignmentStatus::VERIFIED === $assignment->status() => SupervisorVerificationView::VERIFIED,
            default => SupervisorVerificationView::PENDING,
        };

        return new SupervisorVerificationView(
            $state,
            $this->profiles->displayName($assignment->supervisorUserId()),
            $this->profiles->maskedEmail($assignment->supervisorUserId()),
            $pool->workplaceName ?? '',
            $pool->teamLabel ?? '',
            $assignment->confirmations(),
            $this->policy->requiredConfirmations($team, $assignment->supervisorUserId()),
            $this->policy->canBeReached($team, $assignment->supervisorUserId()),
            $mine,
        );
    }
}
