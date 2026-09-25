<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

/**
 * The people who can vouch for a supervisor of one pool: workers with an active
 * membership, on an active assignment, in an active pool — read at the moment
 * the question is asked. Somebody with the link and no membership is not here.
 */
final readonly class SwapPoolTeam
{
    /** @var list<string> */
    public array $activeMemberIds;

    /** @param list<string> $activeMemberIds */
    public function __construct(public string $swapPoolId, array $activeMemberIds)
    {
        $this->activeMemberIds = array_values(array_unique($activeMemberIds));
    }

    public function includes(string $workerId): bool
    {
        return \in_array($workerId, $this->activeMemberIds, true);
    }

    /** Everybody who could confirm this candidate: the team minus the candidate. */
    public function eligibleVerifierCount(string $candidateId): int
    {
        return \count(array_filter($this->activeMemberIds, static fn (string $member): bool => $member !== $candidateId));
    }

    /** @return list<string> */
    public function membersExcept(string $workerId): array
    {
        return array_values(array_filter($this->activeMemberIds, static fn (string $member): bool => $member !== $workerId));
    }
}
