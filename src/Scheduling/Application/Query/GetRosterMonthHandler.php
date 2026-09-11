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
    ) {
    }

    public function __invoke(GetRosterMonth $query): RosterMonthView
    {
        $worker = $this->workspace->require($query->workerId);
        $today = $this->calendar->today($worker);
        $month = null === $query->month ? $today->month() : RosterMonth::fromString($query->month);

        $gridStart = $month->firstDay()->plusDays(1 - $month->firstDay()->dayOfWeek());
        $gridEnd = $gridStart->plusDays(41);

        $days = $this->rosterDays->inRange($worker->assignmentId, $gridStart, $gridEnd);
        $byDate = [];
        foreach ($days as $day) {
            $byDate[(string) $day->date()] = $day;
        }

        $cells = [];
        for ($offset = 0; $offset <= 41; ++$offset) {
            $date = $gridStart->plusDays($offset);
            $cells[] = $this->cell($date, $byDate[(string) $date] ?? null, $month, $today);
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

    private function cell(WorkDate $date, ?RosterDay $day, RosterMonth $month, WorkDate $today): RosterDayCell
    {
        $inMonth = $month->contains($date);
        $isToday = $date->equals($today);
        $weekend = $date->dayOfWeek() >= 6;
        $spoken = \sprintf('%d de %s', $date->day, self::MONTH_NAMES[$date->month - 1]);

        if (null === $day) {
            return new RosterDayCell((string) $date, $date->day, $inMonth, $isToday, 'unknown', '', 'unknown', 'Sin información', [], $spoken.', sin información', $weekend);
        }

        if ($day->isRest()) {
            return new RosterDayCell((string) $date, $date->day, $inMonth, $isToday, 'rest', 'L', 'rest', 'Libre', [], $spoken.', libre', $weekend);
        }

        $segments = array_map(static fn (ShiftSegment $segment): string => $segment->describe(), $day->segments());
        $first = $day->firstSegment();
        $label = null === $first ? 'Turno' : $first->labelSnapshot;
        $hours = null === $first ? '' : \sprintf(', de %s a %s', $first->window->start, $first->window->end);
        $tone = null === $first ? 'oncall' : $first->kind->tone();

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
            \sprintf('%s, turno de %s%s', $spoken, mb_strtolower($label), $hours),
            $weekend,
        );
    }

    /** @return list<string> */
    public static function weekdayInitials(): array
    {
        return self::WEEKDAYS;
    }
}
