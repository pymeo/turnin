<?php

declare(strict_types=1);

namespace App\Scheduling\Application\Query;

use App\Scheduling\Application\RosterCalendar;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\AssignedWorker;
use App\Scheduling\Domain\CombinedDayState;
use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\RosterMonth;
use App\Scheduling\Domain\ShiftInterval;
use App\Scheduling\Domain\WorkDate;

final readonly class GetCombinedRosterMonthHandler
{
    private const MONTH_NAMES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private RosterWorkspace $workspace, private RosterDays $days, private RosterCalendar $calendar)
    {
    }

    public function __invoke(GetCombinedRosterMonth $query): CombinedRosterMonthView
    {
        $assignments = $this->workspace->activeAssignments($query->workerId);
        $primary = $this->workspace->requirePrimary($query->workerId);
        $today = $this->calendar->today($primary);
        $month = null === $query->month ? $today->month() : RosterMonth::fromString($query->month);
        $gridStart = $month->firstDay()->plusDays(1 - $month->firstDay()->dayOfWeek());
        $gridEnd = $gridStart->plusDays(41);
        /** @var non-empty-list<string> $assignmentIds */
        $assignmentIds = array_map(static fn (AssignedWorker $worker): string => $worker->assignmentId, $assignments);
        $loaded = $this->days->inRangeForAssignments($assignmentIds, $gridStart->plusDays(-1), $gridEnd);
        $workers = [];
        foreach ($assignments as $assignment) {
            $workers[$assignment->assignmentId] = $assignment;
        }

        $cells = [];
        $shiftCount = 0;
        $overlapCount = 0;
        for ($offset = 0; $offset <= 41; ++$offset) {
            $date = $gridStart->plusDays($offset);
            [$shifts, $known] = $this->shiftsOn($date, $loaded, $workers);
            $overlap = $this->hasOverlap($date, $loaded, $workers);
            $working = [] !== $shifts;
            $state = match (true) {
                $overlap => CombinedDayState::OVERLAPPING,
                $working => CombinedDayState::WORKING,
                $known === \count($assignments) && $known > 0 => CombinedDayState::GLOBAL_FREE,
                $known > 0 => CombinedDayState::PARTIALLY_KNOWN,
                default => CombinedDayState::UNKNOWN,
            };
            if ($month->contains($date)) {
                $shiftCount += \count(array_filter($shifts, static fn (CombinedShiftView $shift): bool => !$shift->startsPreviousDay));
                $overlapCount += $overlap ? 1 : 0;
            }
            $codes = implode(' · ', array_map(static fn (CombinedShiftView $shift): string => $shift->abbreviation, $shifts));
            $cells[] = new CombinedRosterDayCell((string) $date, $date->day, $month->contains($date), $date->equals($today), $date->dayOfWeek() >= 6, $state->value, $shifts, $overlap, \sprintf('%d de %s, %s%s', $date->day, self::MONTH_NAMES[$date->month - 1], '' === $codes ? $state->value : $codes, $overlap ? ', hay turnos que se solapan' : ''));
        }

        return new CombinedRosterMonthView((string) $month, \sprintf('%s %d', ucfirst(self::MONTH_NAMES[$month->month - 1]), $month->year), (string) $month->previous(), (string) $month->next(), (string) $today, $cells, $shiftCount, $overlapCount);
    }

    /** @param list<RosterDay> $days
     * @param array<string, AssignedWorker> $workers
     *
     * @return array{list<CombinedShiftView>, int}
     */
    private function shiftsOn(WorkDate $date, array $days, array $workers): array
    {
        $shifts = [];
        $known = 0;
        foreach ($days as $day) {
            if ($day->date()->equals($date)) {
                ++$known;
            }
            foreach ($day->segments() as $segment) {
                $worker = $workers[$day->workerAssignmentId()] ?? null;
                if (null === $worker) {
                    continue;
                }
                $interval = $segment->intervalOn($day->date(), $worker->timeZone());
                $dateStart = ShiftInterval::materialize($date, \App\Scheduling\Domain\ShiftWindow::fromStrings('00:00', '00:00'), $worker->timeZone());
                $startsPrevious = $day->date()->isBefore($date);
                if (!$day->date()->equals($date) && !($startsPrevious && $interval->endsAt > $dateStart->startsAt)) {
                    continue;
                }
                $shifts[] = new CombinedShiftView($worker->assignmentId, $worker->workplaceName, $worker->destinationName, $segment->labelSnapshot, $segment->abbreviationSnapshot, (string) $segment->window->start, (string) $segment->window->end, $segment->colorSnapshot->value, $startsPrevious);
            }
        }

        return [$shifts, $known];
    }

    /** @param list<RosterDay> $days
     * @param array<string, AssignedWorker> $workers
     */
    private function hasOverlap(WorkDate $date, array $days, array $workers): bool
    {
        /** @var list<array{string, ShiftInterval}> $intervals */
        $intervals = [];
        foreach ($days as $day) {
            if ($day->date()->daysUntil($date) < 0 || $day->date()->daysUntil($date) > 1) {
                continue;
            }
            foreach ($day->segments() as $segment) {
                $worker = $workers[$day->workerAssignmentId()] ?? null;
                if (null !== $worker) {
                    $intervals[] = [$worker->assignmentId, $segment->intervalOn($day->date(), $worker->timeZone())];
                }
            }
        }
        foreach ($intervals as $index => [$assignmentId, $interval]) {
            foreach (\array_slice($intervals, $index + 1) as [$otherAssignmentId, $other]) {
                if ($assignmentId !== $otherAssignmentId && $interval->overlaps($other)) {
                    return true;
                }
            }
        }

        return false;
    }
}
