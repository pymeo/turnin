<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Application;

use App\Scheduling\Application\Command\EnsureShiftPresets;
use App\Scheduling\Application\Command\EnsureShiftPresetsHandler;
use App\Scheduling\Application\Query\GetRosterMonth;
use App\Scheduling\Application\Query\GetRosterMonthHandler;
use App\Scheduling\Application\Query\RosterDayCell;
use App\Scheduling\Application\RosterCalendar;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\RestStreakInsights;
use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ShiftKind;
use App\Scheduling\Domain\ShiftSegment;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\WorkDate;
use App\Tests\Support\Scheduling\FixedAssignedWorkers;
use App\Tests\Support\Scheduling\FrozenClock;
use App\Tests\Support\Scheduling\InMemoryRosterDays;
use App\Tests\Support\Scheduling\InMemoryShiftPresets;
use App\Tests\Support\Scheduling\SequentialRosterIds;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class GetRosterMonthHandlerTest extends TestCase
{
    public function test_the_grid_is_always_six_weeks_starting_on_a_monday(): void
    {
        $view = $this->month();

        self::assertCount(42, $view->cells);
        self::assertSame('2026-08-31', $view->cells[0]->date, 'September 2026 starts on a Tuesday, so the grid opens on 31 August.');
        self::assertSame('2026-10-11', $view->cells[41]->date);
        self::assertFalse($view->cells[0]->inMonth);
        self::assertTrue($view->cells[1]->inMonth);
    }

    public function test_a_day_with_no_information_is_unknown_and_never_shown_as_free(): void
    {
        $cell = $this->cellFor($this->month(), '2026-09-25');

        self::assertSame('unknown', $cell->state);
        self::assertSame('', $cell->abbreviation);
        self::assertSame('unknown', $cell->tone);
        self::assertStringContainsString('sin información', $cell->ariaLabel);
        self::assertStringNotContainsString('libre', $cell->ariaLabel);
    }

    public function test_a_rest_day_reads_as_free_in_the_label_and_the_letter(): void
    {
        $cell = $this->cellFor($this->month(), '2026-09-16');

        self::assertSame('rest', $cell->state);
        self::assertSame('L', $cell->abbreviation);
        self::assertSame('16 de septiembre, libre', $cell->ariaLabel);
    }

    public function test_a_working_day_spells_out_its_hours_for_a_screen_reader(): void
    {
        $cell = $this->cellFor($this->month(), '2026-09-14');

        self::assertSame('working', $cell->state);
        self::assertSame('N', $cell->abbreviation);
        self::assertSame('blue', $cell->tone);
        self::assertSame('14 de septiembre, turno de noche, de 22:00 a 08:00', $cell->ariaLabel);
    }

    public function test_today_is_marked_once(): void
    {
        $view = $this->month();

        self::assertSame('2026-09-11', $view->today);
        self::assertCount(1, array_filter($view->cells, static fn (RosterDayCell $cell): bool => $cell->isToday));
    }

    public function test_the_header_carries_the_neighbouring_months(): void
    {
        $view = $this->month();

        self::assertSame('Septiembre 2026', $view->title);
        self::assertSame('2026-08', $view->previousMonth);
        self::assertSame('2026-10', $view->nextMonth);
    }

    public function test_the_summary_ignores_days_outside_the_month(): void
    {
        $view = $this->month();

        self::assertSame(1, $view->summary->workedDays);
        self::assertSame(1, $view->summary->restDays);
        self::assertSame(1, $view->summary->nightShifts);
        self::assertSame(28, $view->summary->unknownDays);
    }

    public function test_a_first_visit_is_given_three_sensible_presets(): void
    {
        $presets = new InMemoryShiftPresets();
        $handler = new EnsureShiftPresetsHandler(
            new RosterWorkspace(FixedAssignedWorkers::inMadrid(), $presets),
            $presets,
            new SequentialRosterIds(),
            new FrozenClock('2026-09-11T09:00:00+00:00'),
        );

        $handler(new EnsureShiftPresets('worker-1'));
        $seeded = $presets->forAssignment('assignment-1');

        self::assertCount(3, $seeded);
        self::assertSame(['Mañana', 'Tarde', 'Noche'], array_map(static fn ($preset): string => $preset->name(), $seeded));
        self::assertSame('22:00', (string) $seeded[2]->window()->start);
        self::assertTrue($seeded[2]->window()->endsNextDay());
    }

    public function test_seeding_presets_twice_does_not_duplicate_them(): void
    {
        $presets = new InMemoryShiftPresets();
        $handler = new EnsureShiftPresetsHandler(
            new RosterWorkspace(FixedAssignedWorkers::inMadrid(), $presets),
            $presets,
            new SequentialRosterIds(),
            new FrozenClock('2026-09-11T09:00:00+00:00'),
        );

        $handler(new EnsureShiftPresets('worker-1'));
        $handler(new EnsureShiftPresets('worker-1'));

        self::assertCount(3, $presets->forAssignment('assignment-1'));
    }

    private function month(): \App\Scheduling\Application\Query\RosterMonthView
    {
        $roster = new InMemoryRosterDays();
        $roster->apply('assignment-1', [
            $this->night('2026-09-14'),
            RosterDay::rest('d2', 'assignment-1', WorkDate::fromString('2026-09-16'), RosterSource::MANUAL, $this->createdAt()),
            // Outside the month: it must appear in the grid but not in the totals.
            $this->night('2026-08-31'),
        ], []);

        $handler = new GetRosterMonthHandler(
            new RosterWorkspace(FixedAssignedWorkers::inMadrid(), new InMemoryShiftPresets()),
            $roster,
            new RosterCalendar(new FrozenClock('2026-09-11T09:00:00+00:00')),
            new RestStreakInsights(),
        );

        return $handler(new GetRosterMonth('worker-1', '2026-09'));
    }

    private function cellFor(\App\Scheduling\Application\Query\RosterMonthView $view, string $date): RosterDayCell
    {
        foreach ($view->cells as $cell) {
            if ($cell->date === $date) {
                return $cell;
            }
        }

        self::fail($date.' is not in the grid.');
    }

    private function night(string $date): RosterDay
    {
        $segment = new ShiftSegment('s'.$date, 'night', 'Noche', 'N', ShiftWindow::fromStrings('22:00', '08:00'), ShiftKind::NIGHT, 0);

        return RosterDay::working('d'.$date, 'assignment-1', WorkDate::fromString($date), [$segment], RosterSource::MANUAL, $this->createdAt());
    }

    private function createdAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-01T09:00:00+00:00');
    }
}
