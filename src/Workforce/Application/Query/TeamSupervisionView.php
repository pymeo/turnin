<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

/**
 * One pool the viewer works in: who supervises it, who is waiting for the
 * team's confirmation, and the viewer's own role in it.
 */
final readonly class TeamSupervisionView
{
    public const string ROLE_NONE = 'none';
    public const string ROLE_PENDING = 'pending';
    public const string ROLE_VERIFIED = 'verified';

    /**
     * @param list<string>                $verifiedSupervisors   display names
     * @param list<PendingSupervisorView> $pendingSupervisors
     * @param int                         $requiredConfirmations what a request by the viewer would need
     * @param int                         $eligibleVerifiers     colleagues who could confirm the viewer
     */
    public function __construct(
        public string $swapPoolId,
        public string $workplaceName,
        public string $teamLabel,
        public array $verifiedSupervisors,
        public array $pendingSupervisors,
        public bool $viewerIsSupervisor,
        public string $viewerRole = self::ROLE_NONE,
        public ?MySupervisionView $viewerSupervision = null,
        public int $requiredConfirmations = 0,
        public int $eligibleVerifiers = 0,
    ) {
    }

    public function viewerCanRequest(): bool
    {
        return self::ROLE_NONE === $this->viewerRole;
    }

    public function quorumReachable(): bool
    {
        return $this->eligibleVerifiers >= $this->requiredConfirmations;
    }
}
