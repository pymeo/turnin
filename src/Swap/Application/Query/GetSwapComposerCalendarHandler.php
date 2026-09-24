<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\ShiftCompatibilityResolver;
use App\Swap\Application\SwapAccessDenied;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\RestBlockOpportunity;
use App\Swap\Domain\RestBlockOpportunityFinder;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\RosteredDayState;
use App\Swap\Domain\RosteredShift;
use App\Swap\Domain\ShiftCompatibility;
use App\Swap\Domain\ShiftDuration;
use App\Swap\Domain\ShiftObstacle;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapGroups;
use App\Swap\Domain\SwapProposal;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDate;
use App\Swap\Domain\WorkDateLabel;
use App\Swap\Domain\WorkerDisplayNames;
use InvalidArgumentException;

/**
 * "I will do your shift. Which of mine could you do?" — the whole screen, once.
 *
 * The question this answers changed: it used to ask whether a shift of mine was
 * mine, future and in the right pool, which is not the same thing at all. What
 * decides now is whether **the colleague who published the request can work it**,
 * measured against real shift intervals. Their rota is read to answer that and
 * never rendered: the calendar on screen is mine, with a verdict on each of my
 * shifts and a reason when the answer is no.
 *
 * Four weeks, two range reads, one pass of RestBlockOpportunityFinder. Nothing
 * here grows a query per day or per shift, and the template decides nothing.
 */
final readonly class GetSwapComposerCalendarHandler
{
    private const int WEEKS = 4;

    /** As far ahead as a shift can be offered; paging stops there. */
    private const int HORIZON_DAYS = 120;

    /** Loaded either side of the window so a rest block that starts before the
     * first cell still counts, and the rest rule can see the neighbouring day. */
    private const int CONTEXT_PADDING_DAYS = 7;

    public function __construct(
        private SwapWorkspace $workspace,
        private RosteredDays $rosteredDays,
        private SwapRequests $requests,
        private SwapGroups $groups,
        private ShiftCompatibilityResolver $compatibility,
        private RestBlockOpportunityFinder $finder,
        private WorkerDisplayNames $names,
    ) {
    }

    public function __invoke(GetSwapComposerCalendar $query): SwapComposerCalendarView
    {
        $request = $this->workspace->requireVisibleRequest($query->workerId, $query->requestId);
        if ($request->workerId() === $query->workerId) {
            throw SwapAccessDenied::notAMember();
        }
        if (!$request->isOpen()) {
            throw SwapAccessDenied::notYours();
        }

        $poolGroup = $this->workspace->requireGroup($query->workerId, $request->swapPoolId());
        $myGroups = $this->workspace->requireGroups($query->workerId);
        $today = $this->workspace->today($poolGroup);
        $authorName = $this->names->forWorkers([$request->workerId()])[$request->workerId()] ?? 'Un compañero';

        $requestedShifts = $this->rosteredDays->shiftsFor([[$request->workerAssignmentId(), (string) $request->workDate()]]);
        if ([] === $requestedShifts) {
            throw new InvalidArgumentException('Este turno ya no está disponible.');
        }
        $requestedKey = RosteredDay::keyFor($request->workerAssignmentId(), (string) $request->workDate());

        // Can I do their shift at all? If not there is nothing to compose.
        $mine = $this->assignmentsOf($myGroups);
        $canTakeIt = $this->compatibility->assess(array_keys($mine), $requestedShifts, [$requestedKey => true])[$requestedKey] ?? ShiftCompatibility::allowed();

        [$rangeStart, $rangeEnd, $offset, $maxOffset] = $this->window($today, $query->weekOffset);
        $from = $rangeStart->plusDays(-self::CONTEXT_PADDING_DAYS);
        $to = $rangeEnd->plusDays(self::CONTEXT_PADDING_DAYS);

        $calendar = $this->rosteredDays->inRangeForAssignments(array_keys($mine), (string) $from, (string) $to);
        $myShifts = $this->rosteredDays->shiftsInRange(array_keys($mine), (string) $from, (string) $to);

        // Which of my shifts could *they* work? One question, asked in bulk.
        $theirGroups = $this->groups->activeFor($request->workerId());
        $theirPools = array_fill_keys(array_map(static fn (SwapGroup $group): string => $group->poolId, $theirGroups), true);
        $sharedAssignments = [];
        foreach ($myGroups as $group) {
            if (isset($theirPools[$group->poolId])) {
                $sharedAssignments[$group->assignmentId] = true;
            }
        }
        $reachable = [];
        foreach ($myShifts as $shift) {
            $reachable[$shift->dayKey()] = isset($sharedAssignments[$shift->assignmentId]);
        }
        $theirAssignments = array_values(array_unique(array_map(static fn (SwapGroup $group): string => $group->assignmentId, $theirGroups)));
        $verdicts = $this->compatibility->assess($theirAssignments, $myShifts, $reachable, [$requestedKey => true]);

        $openKeys = [];
        foreach ($this->requests->openByWorker($query->workerId, $today) as $open) {
            $openKeys[RosteredDay::keyFor($open->workerAssignmentId(), (string) $open->workDate())] = true;
        }

        $opportunities = [];
        $byAssignment = [];
        foreach ($calendar as $day) {
            $byAssignment[$day->assignmentId][] = $day;
        }
        foreach ($byAssignment as $days) {
            foreach ($this->finder->find($days) as $opportunity) {
                $opportunities[$opportunity->shiftToRelease->key()] = $opportunity;
            }
        }

        $restDates = [];
        $shiftsByDay = [];
        foreach ($calendar as $day) {
            if (RosteredDayState::REST === $day->state) {
                $restDates[$day->date] = true;
            }
        }
        foreach ($myShifts as $shift) {
            $shiftsByDay[$shift->dayKey()][] = $shift;
        }

        $selected = array_fill_keys($query->selectedKeys, true);
        $dayViews = [];
        $shifts = [];
        for ($cursor = $rangeStart; !$cursor->isAfter($rangeEnd); $cursor = $cursor->plusDays(1)) {
            $date = (string) $cursor;
            $dayShifts = [];
            foreach ($mine as $assignmentId => $group) {
                $key = RosteredDay::keyFor($assignmentId, $date);
                if (!isset($shiftsByDay[$key])) {
                    continue;
                }
                $shift = $this->shiftView($shiftsByDay[$key], $group, $cursor, $today, $request, $verdicts[$key] ?? null, isset($openKeys[$key]), $opportunities[$key] ?? null, $authorName);
                $dayShifts[] = $shift;
                $shifts[] = $shift;
            }
            $dayViews[$date] = $this->dayView($cursor, $today, $dayShifts, isset($restDates[$date]), $selected);
        }

        $weeks = [];
        for ($week = 0; $week < self::WEEKS; ++$week) {
            $days = [];
            for ($weekday = 0; $weekday < 7; ++$weekday) {
                $days[] = $dayViews[(string) $rangeStart->plusDays($week * 7 + $weekday)];
            }
            $weeks[] = new SwapComposerWeekView($this->rangeLabel($rangeStart->plusDays($week * 7), $rangeStart->plusDays($week * 7 + 6)), $days);
        }

        return new SwapComposerCalendarView(
            $request->id(),
            $authorName,
            $this->requestedShiftView($requestedShifts, $request, $poolGroup),
            (string) $rangeStart,
            (string) $rangeEnd,
            $this->rangeLabel($rangeStart, $rangeEnd),
            $offset,
            $offset > 0,
            $offset < $maxOffset,
            $weeks,
            $shifts,
            array_values(array_filter($query->selectedKeys, static fn (string $key): bool => '' !== $key)),
            SwapProposal::MAXIMUM_OPTIONS,
            $canTakeIt->compatible ? null : $canTakeIt->explanation,
        );
    }

    /**
     * Four rolling weeks from the Monday of the current week, so the days
     * already behind today keep their context instead of the grid starting
     * mid-week. Paging forward stops where a shift stops being offerable.
     *
     * @return array{WorkDate, WorkDate, int, int}
     */
    private function window(WorkDate $today, int $requestedOffset): array
    {
        $anchor = $today->plusDays(1 - $today->dayOfWeek());
        $lastStart = $today->plusDays(self::HORIZON_DAYS)->dayNumber() - (self::WEEKS * 7 - 1);
        $maxOffset = max(0, intdiv($lastStart - $anchor->dayNumber(), 7));
        $offset = max(0, min($requestedOffset, $maxOffset));
        $start = $anchor->plusDays($offset * 7);

        return [$start, $start->plusDays(self::WEEKS * 7 - 1), $offset, $maxOffset];
    }

    /**
     * @param non-empty-list<RosteredShift> $dayShifts every stretch of that day
     */
    private function shiftView(array $dayShifts, SwapGroup $group, WorkDate $date, WorkDate $today, SwapRequest $request, ?ShiftCompatibility $verdict, bool $alreadyOpen, ?RestBlockOpportunity $opportunity, string $authorName): SwapComposerShiftView
    {
        $first = $dayShifts[0];
        $minutes = 0;
        $hours = [];
        foreach ($dayShifts as $shift) {
            $minutes += $shift->durationMinutes();
            $hours[] = $shift->hours();
        }

        $blockedReason = match (true) {
            !$date->isAfter($today) => 'Ya ha pasado.',
            $alreadyOpen => 'Ya lo has publicado para que alguien lo cubra.',
            null === $verdict || $verdict->compatible => null,
            default => $this->thirdPerson($verdict->obstacle, $authorName),
        };
        $selectable = null === $blockedReason;

        return new SwapComposerShiftView(
            RosteredDay::keyFor($first->assignmentId, $first->date),
            $first->assignmentId,
            $first->date,
            WorkDateLabel::headline($date),
            WorkDateLabel::compact($date),
            $first->label,
            $first->abbreviation,
            implode(' · ', $hours),
            $minutes,
            ShiftDuration::label($minutes),
            $first->endsNextDay,
            $first->shiftKind->value,
            $first->shiftKind->tone(),
            $group->label(),
            $group->workplaceName,
            $selectable,
            $blockedReason,
            $selectable && null !== $opportunity ? \sprintf('Te dejaría %d días seguidos libres', $opportunity->resultingConsecutiveRestDays) : null,
            $selectable && null !== $verdict && '' !== $verdict->explanation ? $verdict->explanation : null,
        );
    }

    /**
     * The reason, without publishing anybody's rota. What the other person is
     * doing on a given day is theirs; that they cannot take a shift is the only
     * part of it this screen is entitled to say.
     */
    private function thirdPerson(?ShiftObstacle $obstacle, string $name): string
    {
        return match ($obstacle) {
            ShiftObstacle::SHIFT_OVERLAP => \sprintf('%s ya trabaja ese día.', $name),
            ShiftObstacle::INSUFFICIENT_REST => \sprintf('%s no descansaría lo suficiente.', $name),
            ShiftObstacle::NOT_IN_GROUP => \sprintf('%s no trabaja en ese servicio.', $name),
            default => \sprintf('%s no puede hacer este turno.', $name),
        };
    }

    /**
     * @param list<SwapComposerShiftView> $shifts
     * @param array<string, true>         $selected
     */
    private function dayView(WorkDate $date, WorkDate $today, array $shifts, bool $isRest, array $selected): SwapComposerDayView
    {
        $selectable = array_values(array_filter($shifts, static fn (SwapComposerShiftView $shift): bool => $shift->selectable));
        $working = [] !== $shifts;
        $recommended = false;
        foreach ($selectable as $shift) {
            $recommended = $recommended || null !== $shift->recommendation;
        }

        $detail = $working
            ? implode(' y ', array_map(static fn (SwapComposerShiftView $shift): string => \sprintf('%s de %s, %s', $shift->shiftLabel, str_replace('–', ' a ', $shift->hours), $shift->durationLabel), $shifts))
            : ($isRest ? 'libre' : 'sin datos en tu cuadrante');
        $verdict = match (true) {
            !$working => '',
            [] !== $selectable => '. Puede hacerlo'.(null !== $selectable[0]->compatibilityNote ? '. Aviso: '.$selectable[0]->compatibilityNote : ''),
            default => '. '.($shifts[0]->blockedReason ?? ''),
        };

        return new SwapComposerDayView(
            (string) $date,
            $date->day,
            WorkDateLabel::weekday($date),
            WorkDateLabel::compact($date),
            $date->equals($today),
            !$date->isAfter($today) && !$date->equals($today),
            !$working && $isRest,
            !$working && !$isRest,
            $working ? 'Trabajas' : ($isRest ? 'Libre' : 'Sin datos'),
            $shifts,
            [] !== $selectable,
            1 === \count($selectable) ? $selectable[0]->key : null,
            $recommended,
            \sprintf('%s: %s%s%s', WorkDateLabel::headline($date), $detail, $date->equals($today) ? ', hoy' : '', $verdict),
        );
    }

    /**
     * @param non-empty-list<RosteredShift> $shifts
     */
    private function requestedShiftView(array $shifts, SwapRequest $request, SwapGroup $group): SwapComposerShiftView
    {
        $first = $shifts[0];
        $minutes = 0;
        $hours = [];
        foreach ($shifts as $shift) {
            $minutes += $shift->durationMinutes();
            $hours[] = $shift->hours();
        }

        return new SwapComposerShiftView(
            RosteredDay::keyFor($first->assignmentId, $first->date),
            $first->assignmentId,
            $first->date,
            WorkDateLabel::headline($request->workDate()),
            WorkDateLabel::compact($request->workDate()),
            $first->label,
            $first->abbreviation,
            implode(' · ', $hours),
            $minutes,
            ShiftDuration::label($minutes),
            $first->endsNextDay,
            $first->shiftKind->value,
            $first->shiftKind->tone(),
            $group->label(),
            $group->workplaceName,
            false,
            null,
            null,
        );
    }

    /**
     * @param non-empty-list<SwapGroup> $groups
     *
     * @return array<string, SwapGroup> the primary group of each of my calendars
     */
    private function assignmentsOf(array $groups): array
    {
        $mine = [];
        foreach ($groups as $group) {
            if (!isset($mine[$group->assignmentId]) || $group->primary) {
                $mine[$group->assignmentId] = $group;
            }
        }

        return $mine;
    }

    private function rangeLabel(WorkDate $from, WorkDate $to): string
    {
        return WorkDateLabel::short($from).' – '.WorkDateLabel::short($to);
    }
}
