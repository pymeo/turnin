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
            $pool = $this->teams->describe($assignment->swapPoolId());
            if (null === $pool) {
                continue;
            }
            $team = $this->teams->team($assignment->swapPoolId());
            $url = rtrim($query->baseUrl, '/').SupervisionShareMessages::verificationPath($this->tokens->verificationToken($assignment->id()));
            $mine[] = new MySupervisionView(
                $assignment->id(), $assignment->swapPoolId(), $pool->workplaceName, $pool->teamLabel, $assignment->hasApprovalAuthority(),
                $assignment->confirmations(), $this->policy->requiredConfirmations($team, $query->userId), $this->policy->canBeReached($team, $query->userId),
                $url, SupervisionShareMessages::verification($pool->teamLabel, $pool->workplaceName, $url),
            );
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
            $viewerIsSupervisor = false;
            foreach ($this->assignments->activeInPool($poolId) as $assignment) {
                if ($assignment->supervisorUserId() === $query->userId) {
                    $viewerIsSupervisor = true;
                    continue;
                }
                if ($assignment->hasApprovalAuthority()) {
                    $verified[] = $this->profiles->displayName($assignment->supervisorUserId());
                    continue;
                }
                $pending[] = $this->pending($assignment, $pool->teamLabel, $this->policy->requiredConfirmations($team, $assignment->supervisorUserId()), $query->userId);
            }
            $teams[] = new TeamSupervisionView($poolId, $pool->workplaceName, $pool->teamLabel, $verified, $pending, $viewerIsSupervisor);
        }

        return new SupervisionOverview($mine, $teams);
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
