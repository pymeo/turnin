<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Application;

use App\Scheduling\Application\Command\ExportRosterCalendar;
use App\Scheduling\Application\Command\ExportRosterCalendarHandler;
use App\Scheduling\Application\ExternalCalendar\RosterCalendarExporter;
use App\Scheduling\Application\RosterWorkspace;
use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ShiftKind;
use App\Scheduling\Domain\ShiftSegment;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\SuggestedShiftPresets;
use App\Scheduling\Domain\WorkDate;
use App\Tests\Support\Scheduling\FakeExternalCalendarProvider;
use App\Tests\Support\Scheduling\FixedAssignedWorkers;
use App\Tests\Support\Scheduling\InMemoryExternalCalendars;
use App\Tests\Support\Scheduling\InMemoryRosterDays;
use App\Tests\Support\Scheduling\InMemoryShiftPresets;
use App\Tests\Support\Scheduling\SequentialRosterIds;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ExportRosterCalendarHandlerTest extends TestCase
{
    public function test_reexport_updates_the_mapped_event_instead_of_duplicating_it(): void
    {
        $day = RosterDay::working('day', 'assignment-1', WorkDate::fromString('2026-09-15'), [new ShiftSegment('segment', null, 'Mañana', 'M', ShiftWindow::fromStrings('07:00', '15:00'), ShiftKind::MORNING, 0)], RosterSource::MANUAL, new DateTimeImmutable('2026-09-01'));
        $days = new InMemoryRosterDays([$day]);
        $external = new InMemoryExternalCalendars();
        $provider = new FakeExternalCalendarProvider();
        $presets = new InMemoryShiftPresets();
        $workspace = new RosterWorkspace(FixedAssignedWorkers::inMadrid(), $presets, new SuggestedShiftPresets($presets, new SequentialRosterIds(), new MockClock('2026-09-01')));
        $handler = new ExportRosterCalendarHandler($workspace, $days, new RosterCalendarExporter(), $provider, $external, $external, $external);
        $command = new ExportRosterCalendar('worker-1', 'assignment-1', 'google-shifts', WorkDate::fromString('2026-09-01'), WorkDate::fromString('2026-09-30'));

        $first = $handler($command);
        $second = $handler($command);

        self::assertSame(1, $first->created);
        self::assertSame(0, $second->created);
        self::assertSame(1, $second->updated);
        self::assertCount(1, $provider->created);
        self::assertCount(1, $provider->updated);
    }
}
