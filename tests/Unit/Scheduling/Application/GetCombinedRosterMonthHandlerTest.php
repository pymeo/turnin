<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Application;

use App\Scheduling\Application\Query\GetCombinedRosterMonth;
use App\Scheduling\Application\Query\GetCombinedRosterMonthHandler;
use App\Scheduling\Application\RosterCalendar;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\AssignedWorker;
use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ShiftSegment;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\WorkDate;
use App\SharedKernel\Domain\ShiftKind;
use App\Tests\Support\Scheduling\FixedAssignedWorkers;
use App\Tests\Support\Scheduling\InMemoryRosterDays;
use App\Tests\Support\Scheduling\InMemoryShiftPresets;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class GetCombinedRosterMonthHandlerTest extends TestCase
{
    public function test_it_detects_same_day_and_cross_date_overlaps_and_global_free_state(): void
    {
        $a = new AssignedWorker('worker', 'a', 'Europe/Madrid', 'Hospital A', true, 'Urgencias');
        $b = new AssignedWorker('worker', 'b', 'Europe/Madrid', 'Hospital B', false, 'UCI');
        $workers = new FixedAssignedWorkers([$a, $b]);
        $days = new InMemoryRosterDays([
            $this->work('da', 'a', '2026-09-15', '22:00', '08:00', 'N'),
            $this->work('db', 'b', '2026-09-16', '07:00', '15:00', 'M'),
            RosterDay::rest('ra', 'a', WorkDate::fromString('2026-09-17'), RosterSource::MANUAL, new DateTimeImmutable('2026-09-01')),
            RosterDay::rest('rb', 'b', WorkDate::fromString('2026-09-17'), RosterSource::MANUAL, new DateTimeImmutable('2026-09-01')),
        ]);
        // InMemoryRosterDays seeds all entries even when they belong to different assignments.
        $presets = new InMemoryShiftPresets();
        $workspace = new RosterWorkspace($workers, $presets);
        $view = (new GetCombinedRosterMonthHandler($workspace, $days, new RosterCalendar(new MockClock('2026-09-11T10:00:00+02:00'))))(new GetCombinedRosterMonth('worker', '2026-09'));

        $cells = [];
        foreach ($view->cells as $cell) {
            $cells[$cell->date] = $cell;
        }
        self::assertTrue($cells['2026-09-16']->overlap);
        self::assertSame('overlapping', $cells['2026-09-16']->state);
        self::assertSame('global_free', $cells['2026-09-17']->state);
        self::assertSame(1, $view->overlapCount);
    }

    private function work(string $id, string $assignment, string $date, string $start, string $end, string $abbreviation): RosterDay
    {
        $segment = new ShiftSegment('s-'.$id, null, 'Turno', $abbreviation, ShiftWindow::fromStrings($start, $end), ShiftKind::OTHER, 0);

        return RosterDay::working($id, $assignment, WorkDate::fromString($date), [$segment], RosterSource::MANUAL, new DateTimeImmutable('2026-09-01'));
    }
}
