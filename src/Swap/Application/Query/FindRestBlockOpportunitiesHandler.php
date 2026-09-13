<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\Availabilities;
use App\Swap\Domain\OpportunityScoreWeights;
use App\Swap\Domain\RestBlockOpportunityFinder;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDate;
use App\Swap\Domain\WorkDateLabel;
use App\Swap\Domain\WorkerDisplayNames;

final readonly class FindRestBlockOpportunitiesHandler
{
    public function __construct(private SwapWorkspace $workspace, private RosteredDays $days, private RestBlockOpportunityFinder $finder, private Availabilities $availabilities, private SwapRequests $requests, private WorkerDisplayNames $names)
    {
    }

    /** @return list<RestBlockOpportunityView> */
    public function __invoke(FindRestBlockOpportunities $query): array
    {
        $groups = $this->workspace->requireGroups($query->workerId);
        $today = $this->workspace->today($groups[0]);
        $primary = [];
        foreach ($groups as $group) {
            if (!isset($primary[$group->assignmentId]) || $group->primary) {
                $primary[$group->assignmentId] = $group;
            }
        }
        $calendar = $this->days->inRangeForAssignments(array_keys($primary), (string) $today->plusDays(1), (string) $today->plusDays(56));
        $byAssignment = [];
        foreach ($calendar as $day) {
            $byAssignment[$day->assignmentId][] = $day;
        }
        $views = [];
        foreach ($byAssignment as $assignmentId => $days) {
            $group = $primary[$assignmentId] ?? null;
            if (!$group instanceof SwapGroup) {
                continue;
            }
            foreach ($this->finder->find($days) as $opportunity) {
                $date = WorkDate::fromString($opportunity->shiftToRelease->date);
                $candidates = $this->availabilities->activeInPoolOnDate($group->poolId, $date, $opportunity->shiftToRelease->shiftKind, $query->workerId);
                $candidateIds = [];
                foreach ($candidates as $candidate) {
                    if (!$this->days->dayFor($candidate->workerAssignmentId(), (string) $date)->isWorking()) {
                        $candidateIds[] = $candidate->workerId();
                    }
                }
                $candidateNames = $this->names->forWorkers(\array_slice(array_values(array_unique($candidateIds)), 0, 3));
                $existing = $this->requests->openFor($assignmentId, $date);
                $views[] = new RestBlockOpportunityView($assignmentId, $group->poolId, (string) $date, WorkDateLabel::headline($date), $opportunity->shiftToRelease->hours(), $opportunity->shiftToRelease->durationLabel(), $opportunity->shiftToRelease->shiftLabel, $opportunity->resultingConsecutiveRestDays, $opportunity->restStartsAt, $opportunity->restEndsAt, $opportunity->score + (OpportunityScoreWeights::AVAILABLE_CANDIDATE * \count($candidateNames)), [...$opportunity->reasons, ...([] === $candidateNames ? [] : ['hay profesionales compatibles y disponibles'])], array_values($candidateNames), null !== $existing);
            }
        }
        usort($views, static fn (RestBlockOpportunityView $a, RestBlockOpportunityView $b): int => $b->score <=> $a->score);

        return \array_slice($views, 0, 5);
    }
}
