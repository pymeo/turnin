<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Application;

use App\Scheduling\Application\Query\GetNextShift;
use App\Scheduling\Application\Query\GetNextShiftHandler;
use App\Scheduling\Application\RosterCalendar;
use App\Scheduling\Application\RosterWorkspace;
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
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class GetNextShiftHandlerTest extends TestCase
{
    public function test_tonights_shift_is_still_the_next_one_at_nine_in_the_evening(): void
    {
        // 21:00 in Madrid on 11 September.
        $view = $this->ask('2026-09-11T19:00:00+00:00', [$this->night('2026-09-11'), $this->morning('2026-09-13')]);

        self::assertNotNull($view);
        self::assertSame('Hoy', $view->when);
        self::assertSame('Noche', $view->label);
        self::assertSame('22:00–08:00', $view->hours);
        self::assertTrue($view->endsNextDay);
    }

    public function test_a_shift_that_finished_this_morning_is_not_the_next_one(): void
    {
        // 16:00 in Madrid: the morning shift ended at 15:00.
        $view = $this->ask('2026-09-11T14:00:00+00:00', [$this->morning('2026-09-11'), $this->morning('2026-09-12')]);

        self::assertNotNull($view);
        self::assertSame('Mañana', $view->when);
        self::assertSame('2026-09-12', $view->date);
    }

    public function test_rest_days_are_skipped(): void
    {
        $view = $this->ask('2026-09-11T08:00:00+00:00', [
            RosterDay::rest('d1', 'assignment-1', WorkDate::fromString('2026-09-11'), RosterSource::MANUAL, $this->createdAt()),
            RosterDay::rest('d2', 'assignment-1', WorkDate::fromString('2026-09-12'), RosterSource::MANUAL, $this->createdAt()),
            $this->morning('2026-09-15'),
        ]);

        self::assertNotNull($view);
        self::assertSame('2026-09-15', $view->date);
        self::assertSame('Martes 15 de septiembre', $view->when);
    }

    public function test_an_empty_calendar_has_no_next_shift(): void
    {
        self::assertNull($this->ask('2026-09-11T08:00:00+00:00', []));
    }

    public function test_someone_without_a_worker_assignment_simply_has_no_next_shift(): void
    {
        $handler = new GetNextShiftHandler(
            new RosterWorkspace(FixedAssignedWorkers::withoutAssignment(), new InMemoryShiftPresets()),
            new InMemoryRosterDays(),
            new RosterCalendar(new FrozenClock('2026-09-11T08:00:00+00:00')),
        );

        self::assertNull($handler(new GetNextShift('worker-1')));
    }

    /** @param list<RosterDay> $days */
    private function ask(string $instant, array $days): ?\App\Scheduling\Application\Query\NextShiftView
    {
        $roster = new InMemoryRosterDays();
        $roster->apply('assignment-1', $days, []);

        $handler = new GetNextShiftHandler(
            new RosterWorkspace(FixedAssignedWorkers::inMadrid(), new InMemoryShiftPresets()),
            $roster,
            new RosterCalendar(new FrozenClock($instant)),
        );

        return $handler(new GetNextShift('worker-1'));
    }

    private function night(string $date): RosterDay
    {
        $segment = new ShiftSegment('s'.$date, 'night', 'Noche', 'N', ShiftWindow::fromStrings('22:00', '08:00'), ShiftKind::NIGHT, 0);

        return RosterDay::working('d'.$date, 'assignment-1', WorkDate::fromString($date), [$segment], RosterSource::MANUAL, $this->createdAt());
    }

    private function morning(string $date): RosterDay
    {
        $segment = new ShiftSegment('s'.$date, 'morning', 'Mañana', 'M', ShiftWindow::fromStrings('08:00', '15:00'), ShiftKind::MORNING, 0);

        return RosterDay::working('d'.$date, 'assignment-1', WorkDate::fromString($date), [$segment], RosterSource::MANUAL, $this->createdAt());
    }

    private function createdAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-01T09:00:00+00:00');
    }
}
