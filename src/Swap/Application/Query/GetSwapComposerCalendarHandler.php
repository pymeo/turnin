<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\Swap\Application\SwapAccessDenied;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\ExchangeBalances;
use App\Swap\Domain\OpportunityScoreWeights;
use App\Swap\Domain\RestBlockOpportunity;
use App\Swap\Domain\RestBlockOpportunityFinder;
use App\Swap\Domain\ReturnPreference;
use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\RosteredDayState;
use App\Swap\Domain\ShiftBalance;
use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapRequest;
use App\Swap\Domain\SwapRequests;
use App\Swap\Domain\WorkDate;
use App\Swap\Domain\WorkDateLabel;
use App\Swap\Domain\WorkerDisplayNames;
use InvalidArgumentException;

/**
 * The whole "choose what to ask for in return" screen, in one read.
 *
 * The old screen listed the worker's next shifts as identical cards, which is
 * the one shape that cannot answer the question people actually ask: do I work
 * the day before, do I work the day after, and does giving this one away buy me
 * a run of days off. So the read model is a calendar — rest days included,
 * because a blank cell means "I have not filled that in" and never "libre" —
 * and every derived number arrives already computed.
 *
 * Four weeks, one range query, one pass of RestBlockOpportunityFinder per
 * calendar. Nothing here grows a query per day or per shift, and the template
 * never runs the detector.
 */
final readonly class GetSwapComposerCalendarHandler
{
    private const int WEEKS = 4;

    /** As far ahead as GetChangesSetup will offer a shift; paging stops there. */
    private const int HORIZON_DAYS = 120;

    /**
     * Days loaded either side of the window. A rest block that starts before
     * the first cell still counts, so the detector has to see those days even
     * though they are never drawn.
     */
    private const int CONTEXT_PADDING_DAYS = 7;

    private const int RECOMMENDATION_LIMIT = 2;

    public function __construct(
        private SwapWorkspace $workspace,
        private RosteredDays $rosteredDays,
        private SwapRequests $requests,
        private RestBlockOpportunityFinder $finder,
        private ExchangeBalances $balances,
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

        // The membership is what says which of *my* calendars reaches that pool.
        $poolGroup = $this->workspace->requireGroup($query->workerId, $request->swapPoolId());
        $groups = $this->workspace->requireGroups($query->workerId);
        $today = $this->workspace->today($poolGroup);

        $requestedDay = $this->rosteredDays->dayFor($request->workerAssignmentId(), (string) $request->workDate());
        if (!$requestedDay->isWorking()) {
            throw new InvalidArgumentException('Este turno ya no está disponible.');
        }

        $groupsByAssignment = [];
        $reachesPool = [];
        foreach ($groups as $group) {
            if (!isset($groupsByAssignment[$group->assignmentId]) || $group->primary) {
                $groupsByAssignment[$group->assignmentId] = $group;
            }
            if ($group->poolId === $request->swapPoolId()) {
                $reachesPool[$group->assignmentId] = true;
            }
        }

        [$rangeStart, $rangeEnd, $offset, $maxOffset] = $this->window($today, $query->weekOffset);
        $from = $rangeStart->plusDays(-self::CONTEXT_PADDING_DAYS);
        $to = $rangeEnd->plusDays(self::CONTEXT_PADDING_DAYS);

        $calendar = $this->rosteredDays->inRangeForAssignments(array_keys($groupsByAssignment), (string) $from, (string) $to);

        $byAssignment = [];
        foreach ($calendar as $day) {
            $byAssignment[$day->assignmentId][] = $day;
        }
        $opportunities = [];
        foreach ($byAssignment as $days) {
            foreach ($this->finder->find($days) as $opportunity) {
                $opportunities[$opportunity->shiftToRelease->key()] = $opportunity;
            }
        }

        $openKeys = [];
        foreach ($this->requests->openByWorker($query->workerId, $today) as $open) {
            $openKeys[RosteredDay::keyFor($open->workerAssignmentId(), (string) $open->workDate())] = true;
        }

        // A favour already owed between these two is the strongest reason to
        // pick one shift over another, and what the creditor asked for is the
        // second. Two queries, both bounded by the pair of workers.
        $owedToMe = $this->balances->openBetween($query->workerId, $request->workerId());
        $owedByMe = $this->balances->openBetween($request->workerId(), $query->workerId);
        $pendingBonus = [] === $owedToMe && [] === $owedByMe ? 0 : OpportunityScoreWeights::PENDING_EXCHANGE;
        $preference = null;
        foreach ($owedByMe as $balance) {
            $preference = $balance->preference() ?? $preference;
        }

        $working = [];
        $restDates = [];
        foreach ($calendar as $day) {
            if ($day->isWorking()) {
                $working[$day->date][] = $day;
                continue;
            }
            if (RosteredDayState::REST === $day->state) {
                $restDates[$day->date] = true;
            }
        }

        $requestedMinutes = $requestedDay->durationMinutes();
        $dayViews = [];
        $shifts = [];
        for ($cursor = $from; !$cursor->isAfter($to); $cursor = $cursor->plusDays(1)) {
            $date = (string) $cursor;
            $inWindow = $date >= (string) $rangeStart && $date <= (string) $rangeEnd;
            $dayShifts = [];
            foreach ($working[$date] ?? [] as $day) {
                $shift = $this->shiftView($day, $cursor, $today, $request, $requestedMinutes, $reachesPool, $openKeys, $opportunities, $groupsByAssignment, $pendingBonus, $preference);
                $dayShifts[] = $shift;
                if ($inWindow) {
                    $shifts[] = $shift;
                }
            }
            $dayViews[$date] = $this->dayView($cursor, $today, $dayShifts, isset($restDates[$date]));
        }

        $weeks = [];
        for ($week = 0; $week < self::WEEKS; ++$week) {
            $days = [];
            for ($weekday = 0; $weekday < 7; ++$weekday) {
                $days[] = $dayViews[(string) $rangeStart->plusDays($week * 7 + $weekday)];
            }
            $weeks[] = new SwapComposerWeekView($this->rangeLabel($rangeStart->plusDays($week * 7), $rangeStart->plusDays($week * 7 + 6)), $days);
        }

        $names = $this->names->forWorkers([$request->workerId()]);

        return new SwapComposerCalendarView(
            $request->id(),
            $names[$request->workerId()] ?? 'Un compañero',
            $this->requestedShiftView($requestedDay, $request, $poolGroup),
            (string) $rangeStart,
            (string) $rangeEnd,
            $this->rangeLabel($rangeStart, $rangeEnd),
            $offset,
            $offset > 0,
            $offset < $maxOffset,
            $weeks,
            $shifts,
            $this->recommendations($shifts, $dayViews),
            $this->selected($query->selectedShiftKey, $shifts, $today, $request, $requestedMinutes, $reachesPool, $openKeys, $opportunities, $groupsByAssignment, $pendingBonus, $preference),
            $this->rosteredDays->dayFor($poolGroup->assignmentId, (string) $request->workDate())->isWorking()
                ? 'Ya trabajas ese día, así que de momento no puedes coger este turno.'
                : null,
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
     * @param array<string, true>                 $reachesPool
     * @param array<string, true>                 $openKeys
     * @param array<string, RestBlockOpportunity> $opportunities
     * @param array<string, SwapGroup>            $groupsByAssignment
     */
    private function shiftView(
        RosteredDay $day,
        WorkDate $date,
        WorkDate $today,
        SwapRequest $request,
        int $requestedMinutes,
        array $reachesPool,
        array $openKeys,
        array $opportunities,
        array $groupsByAssignment,
        int $pendingBonus,
        ?ReturnPreference $preference,
    ): SwapComposerShiftView {
        $blockedReason = match (true) {
            !$date->isAfter($today) => 'Solo puedes ofrecer turnos futuros.',
            $date->equals($request->workDate()) => 'Es el mismo día que vas a cubrir.',
            !isset($reachesPool[$day->assignmentId]) => 'Este turno es de otro grupo y no entra en este cambio.',
            isset($openKeys[$day->key()]) => 'Ya lo has publicado para que alguien lo cubra.',
            default => null,
        };
        $selectable = null === $blockedReason;
        $opportunity = $selectable && isset($opportunities[$day->key()]) ? $this->opportunityView($opportunities[$day->key()]) : null;
        $balance = ShiftBalance::forProposer($requestedMinutes, $day->durationMinutes());
        $group = $groupsByAssignment[$day->assignmentId] ?? null;
        $groupLabel = null === $group ? '' : $group->label();
        $workplaceName = null === $group ? '' : $group->workplaceName;

        return new SwapComposerShiftView(
            $day->key(),
            $day->assignmentId,
            $day->date,
            WorkDateLabel::headline($date),
            WorkDateLabel::compact($date),
            $day->shiftLabel,
            $day->abbreviation,
            $day->hours(),
            $day->durationMinutes(),
            $day->durationLabel(),
            $day->endsNextDay,
            $day->shiftKind->value,
            $day->shiftKind->tone(),
            $groupLabel,
            $workplaceName,
            $selectable,
            $blockedReason,
            $balance->minutes,
            $balance->label(),
            $balance->hint(),
            $opportunity,
            $selectable ? $this->score($opportunity, $balance->minutes, $date, $today, $day, $pendingBonus, $preference) : 0,
        );
    }

    /**
     * The priorities of the brief as one number: the rest block the exchange
     * would create first, then a favour already pending with this colleague and
     * what they asked for, then how close the two shifts are in length, then how
     * soon it is. See docs/DECISIONS.md.
     */
    private function score(?SwapComposerOpportunityView $opportunity, int $balanceMinutes, WorkDate $date, WorkDate $today, RosteredDay $day, int $pendingBonus, ?ReturnPreference $preference): int
    {
        $score = (null === $opportunity ? 0 : $opportunity->score) + $pendingBonus;
        if (null !== $preference) {
            [$preferenceScore] = $preference->match($date, $day->shiftKind, $day->durationMinutes());
            $score += $preferenceScore;
        }
        $score += max(0, OpportunityScoreWeights::CLOSE_DURATION - 5 * intdiv(abs($balanceMinutes), 60));

        return $score + max(0, OpportunityScoreWeights::NEARBY_DATE - intdiv(max(0, $date->dayNumber() - $today->dayNumber()), 7));
    }

    /** @param list<SwapComposerShiftView> $shifts */
    private function dayView(WorkDate $date, WorkDate $today, array $shifts, bool $isRest): SwapComposerDayView
    {
        $selectable = array_values(array_filter($shifts, static fn (SwapComposerShiftView $shift): bool => $shift->selectable));
        $working = [] !== $shifts;

        $best = null;
        foreach ($selectable as $shift) {
            if (null !== $shift->opportunity && (null === $best || $shift->opportunity->score > $best->score)) {
                $best = $shift->opportunity;
            }
        }

        $detail = $working
            ? implode(' y ', array_map(static fn (SwapComposerShiftView $shift): string => \sprintf('%s de %s, %s', $shift->shiftLabel, str_replace('–', ' a ', $shift->hours), $shift->durationLabel), $shifts))
            : ($isRest ? 'libre' : 'sin datos en tu cuadrante');

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
            $best,
            \sprintf(
                '%s: %s%s%s',
                WorkDateLabel::headline($date),
                $detail,
                $date->equals($today) ? ', hoy' : '',
                null === $best ? '' : \sprintf('. Cederlo te dejaría %s', $best->summaryLabel),
            ),
        );
    }

    private function opportunityView(RestBlockOpportunity $opportunity): SwapComposerOpportunityView
    {
        return new SwapComposerOpportunityView(
            $opportunity->resultingConsecutiveRestDays,
            $opportunity->gainedRestDays,
            $opportunity->restStartsAt,
            $opportunity->restEndsAt,
            WorkDateLabel::compact(WorkDate::fromString($opportunity->restStartsAt)).' → '.WorkDateLabel::compact(WorkDate::fromString($opportunity->restEndsAt)),
            $opportunity->resultingConsecutiveRestDays.' días',
            $opportunity->resultingConsecutiveRestDays.' días seguidos libres',
            $opportunity->reasons,
            $opportunity->score,
        );
    }

    /**
     * One suggestion, or two when the second buys a different rest block that is
     * at least as long. Marking every shift as an opportunity is the same as
     * marking none.
     *
     * @param list<SwapComposerShiftView>        $shifts
     * @param array<string, SwapComposerDayView> $dayViews
     *
     * @return list<SwapComposerRecommendationView>
     */
    private function recommendations(array $shifts, array $dayViews): array
    {
        $ranked = array_values(array_filter($shifts, static fn (SwapComposerShiftView $shift): bool => $shift->selectable && null !== $shift->opportunity));
        usort($ranked, static fn (SwapComposerShiftView $a, SwapComposerShiftView $b): int => $b->score <=> $a->score);

        $recommendations = [];
        foreach ($ranked as $shift) {
            $opportunity = $shift->opportunity;
            if (null === $opportunity || \count($recommendations) >= self::RECOMMENDATION_LIMIT) {
                break;
            }
            $first = $recommendations[0] ?? null;
            if (null !== $first && ($opportunity->restStartsAt === $first->opportunity->restStartsAt || $opportunity->resultingRestDays < $first->opportunity->resultingRestDays)) {
                continue;
            }
            $recommendations[] = new SwapComposerRecommendationView($shift, $opportunity, 'Conseguirías '.$opportunity->summaryLabel, $this->timeline($dayViews, $opportunity));
        }

        return $recommendations;
    }

    /**
     * @param array<string, SwapComposerDayView> $dayViews
     *
     * @return list<SwapComposerDayView>
     */
    private function timeline(array $dayViews, SwapComposerOpportunityView $opportunity): array
    {
        $to = WorkDate::fromString($opportunity->restEndsAt)->plusDays(1);
        $days = [];
        for ($cursor = WorkDate::fromString($opportunity->restStartsAt)->plusDays(-1); !$cursor->isAfter($to); $cursor = $cursor->plusDays(1)) {
            $day = $dayViews[(string) $cursor] ?? null;
            if (null !== $day) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * A selection survives paging to another week, so it has to be resolvable
     * outside the window too — and it arrives from a browser, so the assignment
     * is checked against the worker's own calendars before anything is read.
     *
     * @param list<SwapComposerShiftView>         $shifts
     * @param array<string, true>                 $reachesPool
     * @param array<string, true>                 $openKeys
     * @param array<string, RestBlockOpportunity> $opportunities
     * @param array<string, SwapGroup>            $groupsByAssignment
     */
    private function selected(
        ?string $key,
        array $shifts,
        WorkDate $today,
        SwapRequest $request,
        int $requestedMinutes,
        array $reachesPool,
        array $openKeys,
        array $opportunities,
        array $groupsByAssignment,
        int $pendingBonus,
        ?ReturnPreference $preference,
    ): ?SwapComposerShiftView {
        if (null === $key || '' === $key) {
            return null;
        }
        foreach ($shifts as $shift) {
            if ($shift->key === $key && $shift->selectable) {
                return $shift;
            }
        }

        $parts = explode('|', $key, 2);
        if (2 !== \count($parts) || !isset($groupsByAssignment[$parts[0]])) {
            return null;
        }
        try {
            $date = WorkDate::fromString($parts[1]);
        } catch (InvalidArgumentException) {
            return null;
        }
        $day = $this->rosteredDays->dayFor($parts[0], (string) $date);
        if (!$day->isWorking()) {
            return null;
        }
        $shift = $this->shiftView($day, $date, $today, $request, $requestedMinutes, $reachesPool, $openKeys, $opportunities, $groupsByAssignment, $pendingBonus, $preference);

        return $shift->selectable ? $shift : null;
    }

    private function rangeLabel(WorkDate $from, WorkDate $to): string
    {
        return WorkDateLabel::short($from).' – '.WorkDateLabel::short($to);
    }

    /** The shift being taken. No balance and no opportunity: it is not a choice. */
    private function requestedShiftView(RosteredDay $day, SwapRequest $request, SwapGroup $group): SwapComposerShiftView
    {
        $date = $request->workDate();

        return new SwapComposerShiftView(
            $day->key(),
            $day->assignmentId,
            $day->date,
            WorkDateLabel::headline($date),
            WorkDateLabel::compact($date),
            $day->shiftLabel,
            $day->abbreviation,
            $day->hours(),
            $day->durationMinutes(),
            $day->durationLabel(),
            $day->endsNextDay,
            $day->shiftKind->value,
            $day->shiftKind->tone(),
            $group->label(),
            $group->workplaceName,
            false,
            null,
            0,
            '',
            '',
            null,
            0,
        );
    }
}
