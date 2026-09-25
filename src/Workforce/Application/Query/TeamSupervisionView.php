<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

final readonly class TeamSupervisionView
{
    /**
     * @param list<string>                $verifiedSupervisors display names
     * @param list<PendingSupervisorView> $pendingSupervisors
     */
    public function __construct(
        public string $swapPoolId,
        public string $workplaceName,
        public string $teamLabel,
        public array $verifiedSupervisors,
        public array $pendingSupervisors,
        public bool $viewerIsSupervisor,
    ) {
    }
}
