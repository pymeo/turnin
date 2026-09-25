<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Application\RosterCalendar;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\CalendarInsight;
use App\Scheduling\Domain\CalendarInsightSource;
use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\RosterMonth;
use App\Scheduling\Domain\RosterMonthSummary;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ShiftSegment;
use App\Scheduling\Domain\WorkDate;

/**
 * One month, one query. The grid always covers six weeks so the layout never
 * jumps between months, and the days outside the month are rendered dimmed
 * rather than blank — a calendar that changes height when you page through it
 * feels broken on a phone.
 *
 * Cost: one range query for the days, one for the presets the screen needs.
 * Nothing here loads a year, and nothing loops a query.
 */
final readonly class GetRosterMonthHandler
{
    private const WEEKDAYS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

    private const MONTH_NAMES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(
        private RosterWorkspace $workspace,
        private RosterDays $rosterDays,
        private RosterCalendar $calendar,
        private CalendarInsightSource $insights,
        private ?RosterSwapTraces $swapTraces = null,
    ) {
    }

    public function __invoke(GetRosterMonth $query): RosterMonthView
    {
        $worker = $this->workspace->require($query->workerId, $query->assignmentId);
        $today = $this->calendar->today($worker);
        $month = null === $query->month ? $today->month() : RosterMonth::fromString($query->month);

        $gridStart = $month->firstDay()->plusDays(1 - $month->firstDay()->dayOfWeek());
        $gridEnd = $gridStart->plusDays(41);

        $days = $this->rosterDays->inRange($worker->assignmentId, $gridStart, $gridEnd);
        $byDate = [];
        foreach ($days as $day) {
            $byDate[(string) $day->date()] = $day;
        }
        $tracesByDate = [];
        foreach ($this->swapTraces?->forWorkerInRange($query->workerId, (string) $gridStart, (string) $gridEnd) ?? [] as $trace) {
            $tracesByDate[$trace->date][] = $trace;
        }

        $cells = [];
        for ($offset = 0; $offset <= 41; ++$offset) {
            $date = $gridStart->plusDays($offset);
            $cells[] = $this->cell($date, $byDate[(string) $date] ?? null, $month, $today, $tracesByDate[(string) $date] ?? []);
        }

        $inMonth = array_values(array_filter($days, static fn (RosterDay $day): bool => $month->contains($day->date())));
        $summary = RosterMonthSummary::of($month, $inMonth);

        return new RosterMonthView(
            (string) $month,
            \sprintf('%s %d', ucfirst(self::MONTH_NAMES[$month->month - 1]), $month->year),
            (string) $month->previous(),
            (string) $month->next(),
            (string) $today,
            $cells,
            new RosterSummaryView($summary->workedDays, $summary->restDays, $summary->nightShifts, $summary->unknownDays, $summary->longestRestStreak, $summary->isEmpty()),
            array_map(static fn (CalendarInsight $insight): CalendarInsightView => new CalendarInsightView($insight->headline, $insight->detail), $this->insights->insightsFor($month, $inMonth)),
            $this->rosterDays->countFor($worker->assignmentId) > 0,
        );
    }

    /** @param list<RosterSwapTrace> $swapTraces */
    private function cell(WorkDate $date, ?RosterDay $day, RosterMonth $month, WorkDate $today, array $swapTraces): RosterDayCell
    {
        $inMonth = $month->contains($date);
        $isToday = $date->equals($today);
        $weekend = $date->dayOfWeek() >= 6;
        $spoken = \sprintf('%d de %s', $date->day, self::MONTH_NAMES[$date->month - 1]);

        if (null === $day) {
            return new RosterDayCell((string) $date, $date->day, $inMonth, $isToday, 'unknown', '', 'unknown', 'Sin información', [], $this->aria($spoken.', sin información', $swapTraces), $weekend, [] !== $swapTraces, [], $swapTraces);
        }

        if ($day->isRest()) {
            $fromSwap = RosterSource::SWAP === $day->source() || [] !== $swapTraces;
            $aria = $spoken.', libre';
            if (RosterSource::SWAP === $day->source() && [] === $swapTraces) {
                $aria .= ' tras un cambio';
            }

            return new RosterDayCell((string) $date, $date->day, $inMonth, $isToday, 'rest', 'L', 'rest', 'Libre', [], $this->aria($aria, $swapTraces), $weekend, $fromSwap, [], $swapTraces);
        }

        $segments = array_map(static fn (ShiftSegment $segment): string => $segment->describe(), $day->segments());
        $first = $day->firstSegment();
        $label = null === $first ? 'Turno' : $first->labelSnapshot;
        $hours = null === $first ? '' : \sprintf(', de %s a %s', $first->window->start, $first->window->end);
        $tone = null === $first ? 'slate' : $first->colorSnapshot->value;
        $shiftSegments = array_map(fn (ShiftSegment $segment): RosterShiftSegmentView => $this->segmentView($segment, $day->source(), $swapTraces), $day->segments());

        $aria = \sprintf('%s, turno de %s%s', $spoken, mb_strtolower($label), $hours);
        if (RosterSource::SWAP === $day->source() && [] === $swapTraces) {
            $aria .= ', recibido mediante un cambio';
        }

        return new RosterDayCell(
            (string) $date,
            $date->day,
            $inMonth,
            $isToday,
            'working',
            $day->abbreviation(),
            $tone,
            $label,
            $segments,
            $this->aria($aria, $swapTraces),
            $weekend,
            RosterSource::SWAP === $day->source() || [] !== $swapTraces,
            $shiftSegments,
            $swapTraces,
        );
    }

    /** @param list<RosterSwapTrace> $traces */
    private function segmentView(ShiftSegment $segment, RosterSource $source, array $traces): RosterShiftSegmentView
    {
        foreach ($traces as $trace) {
            if (RosterSwapRole::TAKEN_FROM_COLLEAGUE !== $trace->role) {
                continue;
            }
            foreach ($trace->segments as $swapSegment) {
                if ((string) $segment->window->start === $swapSegment->start && (string) $segment->window->end === $swapSegment->end && $segment->labelSnapshot === $swapSegment->label) {
                    return new RosterShiftSegmentView($swapSegment->start, $swapSegment->end, $swapSegment->durationMinutes, $this->duration($swapSegment->durationMinutes), $segment->labelSnapshot, $segment->abbreviationSnapshot, $segment->colorSnapshot->value, $source->value, $trace->role, $trace->colleagueDisplayName, $trace->agreementId, $trace->status);
                }
            }
        }
        $minutes = $segment->window->durationInMinutes();

        return new RosterShiftSegmentView((string) $segment->window->start, (string) $segment->window->end, $minutes, $this->duration($minutes), $segment->labelSnapshot, $segment->abbreviationSnapshot, $segment->colorSnapshot->value, $source->value);
    }

    /** @param list<RosterSwapTrace> $traces */
    private function aria(string $base, array $traces): string
    {
        $parts = [$base];
        foreach ($traces as $trace) {
            $state = match ($trace->status) {
                RosterAgreementStatus::PENDING => 'acordado y pendiente del centro',
                RosterAgreementStatus::CONFIRMED => 'confirmado',
                RosterAgreementStatus::CANCELLED => 'cancelado',
            };
            $parts[] = RosterSwapRole::GIVEN_AWAY === $trace->role
                ? 'turno cubierto por '.$trace->colleagueDisplayName.' mediante cambio '.$state
                : 'turno que haces por '.$trace->colleagueDisplayName.' mediante cambio '.$state;
        }

        return implode(', ', $parts);
    }

    private function duration(int $minutes): string
    {
        return 0 === $minutes % 60 ? intdiv($minutes, 60).' h' : intdiv($minutes, 60).' h '.($minutes % 60).' min';
    }

    /** @return list<string> */
    public static function weekdayInitials(): array
    {
        return self::WEEKDAYS;
    }
}
