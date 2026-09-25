<?php

declare(strict_types=1);

namespace App\Workforce\Domain\Supervision;

use InvalidArgumentException;

/**
 * How many colleagues must confirm a supervisor before they get authority.
 *
 * The only place that answers the question, so no controller or template can
 * hardcode a "2". The team is counted without the candidate: a nurse who is
 * also asking to be the supervisor of her own unit cannot be one of her votes.
 *
 * When the team is too small to ever reach the number, the assignment stays
 * pending. There is no fallback that lowers the bar: a verification that
 * nobody could have refused is not a verification. Organizations will be able
 * to verify administratively instead (ORGANIZATION_VERIFIED), not before.
 */
final readonly class SupervisorVerificationPolicy
{
    public function __construct(
        private int $smallTeamConfirmations = 2,
        private int $largeTeamConfirmations = 3,
        private int $largeTeamFrom = 5,
    ) {
        if ($smallTeamConfirmations < 2 || $largeTeamConfirmations < $smallTeamConfirmations || $largeTeamFrom < 2) {
            throw new InvalidArgumentException('Verifying a supervisor needs at least two confirmations.');
        }
    }

    public function requiredConfirmations(SwapPoolTeam $team, string $candidateId): int
    {
        return $team->eligibleVerifierCount($candidateId) >= $this->largeTeamFrom ? $this->largeTeamConfirmations : $this->smallTeamConfirmations;
    }

    public function canBeReached(SwapPoolTeam $team, string $candidateId): bool
    {
        return $team->eligibleVerifierCount($candidateId) >= $this->requiredConfirmations($team, $candidateId);
    }
}
