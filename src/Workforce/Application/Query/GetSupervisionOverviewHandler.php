<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

use App\Workforce\Application\Supervision\SupervisionShareMessages;
use App\Workforce\Domain\Supervision\SupervisorAssignment;
use App\Workforce\Domain\Supervision\SupervisorAssignments;
use App\Workforce\Domain\Supervision\SupervisorLinkTokens;
use App\Workforce\Domain\Supervision\SupervisorProfiles;
use App\Workforce\Domain\Supervision\SupervisorVerificationPolicy;
use App\Workforce\Domain\Supervision\SwapPoolTeams;

final readonly class GetSupervisionOverviewHandler
{
    public function __construct(private SupervisorAssignments $assignments, private SwapPoolTeams $teams, private SupervisorLinkTokens $tokens, private SupervisorProfiles $profiles, private SupervisorVerificationPolicy $policy)
    {
    }

    public function __invoke(GetSupervisionOverview $query): SupervisionOverview
    {
        $mine = [];
        foreach ($this->assignments->activeForSupervisor($query->userId) as $assignment) {
            $supervision = $this->mine($assignment, $query);
            if (null !== $supervision) {
                $mine[$assignment->swapPoolId()] = $supervision;
            }
        }

        $teams = [];
        foreach ($this->teams->poolsOf($query->userId) as $poolId) {
            $pool = $this->teams->describe($poolId);
            if (null === $pool) {
                continue;
            }
            $team = $this->teams->team($poolId);
            $verified = [];
            $pending = [];
            foreach ($this->assignments->activeInPool($poolId) as $assignment) {
                if ($assignment->supervisorUserId() === $query->userId) {
                    continue;
                }
                if ($assignment->hasApprovalAuthority()) {
                    $verified[] = $this->profiles->displayName($assignment->supervisorUserId());
                    continue;
                }
                $pending[] = $this->pending($assignment, $pool->teamLabel, $this->policy->requiredConfirmations($team, $assignment->supervisorUserId()), $query->userId);
            }
            $own = $mine[$poolId] ?? null;
            $role = null === $own ? TeamSupervisionView::ROLE_NONE : ($own->verified ? TeamSupervisionView::ROLE_VERIFIED : TeamSupervisionView::ROLE_PENDING);
            $teams[] = new TeamSupervisionView(
                $poolId, $pool->workplaceName, $pool->teamLabel, $verified, $pending, null !== $own, $role, $own,
                $this->policy->requiredConfirmations($team, $query->userId), $team->eligibleVerifierCount($query->userId),
            );
        }

        return new SupervisionOverview(array_values($mine), $teams);
    }

    private function mine(SupervisorAssignment $assignment, GetSupervisionOverview $query): ?MySupervisionView
    {
        $pool = $this->teams->describe($assignment->swapPoolId());
        if (null === $pool) {
            return null;
        }
        $team = $this->teams->team($assignment->swapPoolId());
        $url = rtrim($query->baseUrl, '/').SupervisionShareMessages::verificationPath($this->tokens->verificationToken($assignment->id()));

        return new MySupervisionView(
            $assignment->id(), $assignment->swapPoolId(), $pool->workplaceName, $pool->teamLabel, $assignment->hasApprovalAuthority(),
            $assignment->confirmations(), $this->policy->requiredConfirmations($team, $query->userId), $this->policy->canBeReached($team, $query->userId),
            $url, SupervisionShareMessages::verification($pool->teamLabel, $pool->workplaceName, $url), $team->eligibleVerifierCount($query->userId),
        );
    }

    private function pending(SupervisorAssignment $assignment, string $teamLabel, int $required, string $viewerId): PendingSupervisorView
    {
        return new PendingSupervisorView(
            $this->profiles->displayName($assignment->supervisorUserId()),
            $teamLabel,
            SupervisionShareMessages::verificationPath($this->tokens->verificationToken($assignment->id())),
            $assignment->confirmations(),
            $required,
            $assignment->hasAnswered($viewerId),
        );
    }
}
