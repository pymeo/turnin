<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\ShiftCompatibilityResolver;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\Availabilities;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\RosteredShift;
use App\Swap\Domain\ShiftCompatibility;
use App\Swap\Domain\ShiftDuration;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDateLabel;
use App\Swap\Domain\WorkerDisplayNames;

/**
 * "Turnos de compañeros": what the people I can exchange with are trying to
 * give away, and which of those I could actually do.
 *
 * The pools come from the session, so a shift somebody is not professionally
 * able to do never reaches this list at all — showing it would be noise and
 * notifying about it would be worse. A shift they *are* able to do but are busy
 * for does reach it, greyed out with the reason, because knowing which days
 * your colleagues are trying to free up is useful on the days you cannot help.
 *
 * Availability plays no part in the decision. Saying "I can work that day" in
 * advance is a hint, never a requirement: if you are free and compatible you
 * can offer, whether or not you ever filled anything in.
 */
final readonly class GetOpenSwapRequestsHandler
{
    public function __construct(
        private SwapWorkspace $workspace,
        private SwapRequests $requests,
        private RosteredDays $rosteredDays,
        private ShiftCompatibilityResolver $compatibility,
        private Availabilities $availabilities,
        private WorkerDisplayNames $names,
    ) {
    }

    /** @return list<OpenSwapRequestView> */
    public function __invoke(GetOpenSwapRequests $query): array
    {
        $groups = $this->workspace->groupsFor($query->workerId);
        if ([] === $groups) {
            return [];
        }
        if (null !== $query->swapPoolId) {
            $groups = [$this->workspace->requireGroup($query->workerId, $query->swapPoolId)];
        }

        $byPool = [];
        $myAssignments = [];
        foreach ($groups as $group) {
            $byPool[$group->poolId] = $group;
            $myAssignments[$group->assignmentId] = true;
        }

        $today = $this->workspace->today($groups[0]);
        $open = $this->requests->openInPools(array_keys($byPool), $today, $query->workerId);
        if ([] === $open) {
            return [];
        }

        // Four bulk reads, whatever the number of cards: the shifts published,
        // my own calendar around them, the names, and the days I had flagged.
        $pairs = array_map(
            static fn (SwapRequest $request): array => [$request->workerAssignmentId(), (string) $request->workDate()],
            $open,
        );
        $published = $this->rosteredDays->shiftsFor($pairs);
        if ([] === $published) {
            return [];
        }
        $shifts = $this->groupByDay($published);
        $verdicts = $this->compatibility->assess(array_keys($myAssignments), $published, array_fill_keys(array_keys($shifts), true));
        $names = $this->names->forWorkers(array_values(array_unique(array_map(
            static fn (SwapRequest $request): string => $request->workerId(),
            $open,
        ))));
        $flagged = $this->alreadyOffered($query->workerId, $open);

        $views = [];
        foreach ($open as $request) {
            $key = RosteredDay::keyFor($request->workerAssignmentId(), (string) $request->workDate());
            $day = $shifts[$key] ?? null;
            // The author cleared or changed the day after publishing. The
            // request is stale, so it is not shown rather than shown wrong.
            if (null === $day) {
                continue;
            }
            $verdict = $verdicts[$key] ?? ShiftCompatibility::allowed();
            if (null !== $verdict->obstacle && !$verdict->obstacle->isTemporal()) {
                continue;
            }
            $group = $byPool[$request->swapPoolId()];
            $views[] = $this->view($request, $day, $group, $verdict, $names[$request->workerId()] ?? 'Un compañero', isset($flagged[$request->swapPoolId().'|'.$request->workDate()]));
        }

        // By colleague and then by date: the screen groups under a name, and
        // "which days is David trying to give away" is the question it answers.
        usort($views, static fn (OpenSwapRequestView $a, OpenSwapRequestView $b): int => [$a->authorName, $a->date] <=> [$b->authorName, $b->date]);

        return $views;
    }

    /** @param non-empty-list<RosteredShift> $day */
    private function view(SwapRequest $request, array $day, SwapGroup $group, ShiftCompatibility $verdict, string $authorName, bool $flagged): OpenSwapRequestView
    {
        $minutes = 0;
        $hours = [];
        foreach ($day as $shift) {
            $minutes += $shift->durationMinutes();
            $hours[] = $shift->hours();
        }
        $first = $day[0];

        return new OpenSwapRequestView(
            $request->id(),
            (string) $request->workDate(),
            WorkDateLabel::headline($request->workDate()),
            $first->label,
            $first->abbreviation,
            implode(' · ', $hours),
            $minutes,
            ShiftDuration::label($minutes),
            $first->endsNextDay,
            $first->colorKey,
            $group->label(),
            $group->workplaceName,
            $authorName,
            $verdict->compatible,
            $verdict->obstacle?->value,
            $verdict->explanation,
            $flagged,
        );
    }

    /**
     * @param list<RosteredShift> $shifts
     *
     * @return array<string, non-empty-list<RosteredShift>>
     */
    private function groupByDay(array $shifts): array
    {
        $byDay = [];
        foreach ($shifts as $shift) {
            $byDay[$shift->dayKey()][] = $shift;
        }

        return $byDay;
    }

    /**
     * @param list<SwapRequest> $open
     *
     * @return array<string, true>
     */
    private function alreadyOffered(string $workerId, array $open): array
    {
        $dates = [];
        foreach ($open as $request) {
            $dates[(string) $request->workDate()] = $request->workDate();
        }
        $offered = [];
        foreach ($this->availabilities->activePoolsOnDates($workerId, array_values($dates)) as $slot => $poolIds) {
            foreach ($poolIds as $poolId) {
                [$workDate] = explode('|', $slot, 2);
                $offered[$poolId.'|'.$workDate] = true;
            }
        }

        return $offered;
    }
}
