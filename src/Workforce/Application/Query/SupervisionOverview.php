<?php

declare(strict_types=1);

namespace App\Workforce\Application\Query;

/**
 * Everything the home and the team page need about supervision for one
 * person, in the two roles they may hold at once.
 */
final readonly class SupervisionOverview
{
    /**
     * @param list<MySupervisionView>   $mySupervisions pools where this person is (or is asking to be) the supervisor
     * @param list<TeamSupervisionView> $teams          pools where this person works
     */
    public function __construct(public array $mySupervisions, public array $teams)
    {
    }

    /** @return list<PendingSupervisorView> candidates this worker has not answered about */
    public function checksToDo(): array
    {
        $checks = [];
        foreach ($this->teams as $team) {
            foreach ($team->pendingSupervisors as $pending) {
                if (!$pending->answeredByViewer) {
                    $checks[] = $pending;
                }
            }
        }

        return $checks;
    }

    /** @return list<TeamSupervisionView> */
    public function teamsWithoutSupervisor(): array
    {
        return array_values(array_filter($this->teams, static fn (TeamSupervisionView $team): bool => [] === $team->verifiedSupervisors && !$team->viewerIsSupervisor));
    }

    public function pending(): bool
    {
        return [] !== array_filter($this->mySupervisions, static fn (MySupervisionView $supervision): bool => !$supervision->verified);
    }

    public function verified(): bool
    {
        return [] !== array_filter($this->mySupervisions, static fn (MySupervisionView $supervision): bool => $supervision->verified);
    }
}
