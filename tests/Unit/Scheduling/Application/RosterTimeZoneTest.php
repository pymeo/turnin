<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Application;

use App\Scheduling\Application\RosterCalendar;
use App\Scheduling\Domain\AssignedWorker;
use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ShiftKind;
use App\Scheduling\Domain\ShiftSegment;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\WorkDate;
use App\Tests\Support\Scheduling\FrozenClock;
use App\Workforce\Domain\WorkplaceTimeZone;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Turnin is national, and Spain has two clocks. Everything here is about the
 * consequences of that: at half past midnight in Madrid it is still yesterday
 * in Las Palmas, and twice a year the peninsula changes its offset while a
 * night shift is in progress.
 */
final class RosterTimeZoneTest extends TestCase
{
    #[DataProvider('communities')]
    public function test_the_time_zone_comes_from_the_centre_not_from_a_constant(?string $community, string $expected): void
    {
        self::assertSame($expected, WorkplaceTimeZone::forAutonomousCommunity($community));
    }

    /** @return iterable<string, array{string|null, string}> */
    public static function communities(): iterable
    {
        yield 'the ministry export shouts' => ['CANARIAS', WorkplaceTimeZone::CANARY];
        yield 'and sometimes it does not' => ['Canarias', WorkplaceTimeZone::CANARY];
        yield 'Andalucía is peninsular' => ['ANDALUCÍA', WorkplaceTimeZone::PENINSULAR];
        yield 'so are the Balearics' => ['Illes Balears', WorkplaceTimeZone::PENINSULAR];
        yield 'and an unknown value falls back to the peninsula' => [null, WorkplaceTimeZone::PENINSULAR];
    }

    public function test_the_same_instant_is_a_different_day_in_the_canaries_in_summer(): void
    {
        $calendar = new RosterCalendar(new FrozenClock('2026-09-11T22:30:00+00:00'));

        self::assertSame('2026-09-12', (string) $calendar->today($this->worker('Europe/Madrid')));
        self::assertSame('2026-09-11', (string) $calendar->today($this->worker('Atlantic/Canary')));
    }

    public function test_and_in_winter_too(): void
    {
        $calendar = new RosterCalendar(new FrozenClock('2026-01-15T23:30:00+00:00'));

        self::assertSame('2026-01-16', (string) $calendar->today($this->worker('Europe/Madrid')));
        self::assertSame('2026-01-15', (string) $calendar->today($this->worker('Atlantic/Canary')));
    }

    /**
     * The night the clocks go back, 2026-10-25, the peninsula runs 03:00 → 02:00
     * while somebody is on a 22:00–08:00 shift. That shift belongs to Saturday
     * the 24th and must stay there: its date is a date, and its hours are wall
     * clock, so there is no arithmetic that can move it.
     */
    public function test_a_night_shift_across_the_autumn_clock_change_stays_on_its_own_day(): void
    {
        $night = new ShiftSegment('s1', 'night', 'Noche', 'N', ShiftWindow::fromStrings('22:00', '08:00'), ShiftKind::NIGHT, 0);
        $day = RosterDay::working('d1', 'a1', WorkDate::fromString('2026-10-24'), [$night], RosterSource::MANUAL, new DateTimeImmutable('2026-10-01T09:00:00+00:00'));

        self::assertSame('2026-10-24', (string) $day->date());
        self::assertSame('22:00', (string) $night->window->start);
        self::assertSame('08:00', (string) $night->window->end);
        self::assertTrue($night->endsNextDay());
        // Eleven real hours elapse that night, but the shift is still "22 to 8".
        self::assertSame(600, $night->window->durationInMinutes());
    }

    public function test_a_night_shift_across_the_spring_clock_change_is_equally_unmoved(): void
    {
        $night = new ShiftSegment('s1', 'night', 'Noche', 'N', ShiftWindow::fromStrings('22:00', '08:00'), ShiftKind::NIGHT, 0);
        $day = RosterDay::working('d1', 'a1', WorkDate::fromString('2027-03-27'), [$night], RosterSource::MANUAL, new DateTimeImmutable('2027-03-01T09:00:00+00:00'));

        self::assertSame('2027-03-27', (string) $day->date());
        self::assertSame(600, $night->window->durationInMinutes());
    }

    private function worker(string $timeZone): AssignedWorker
    {
        return new AssignedWorker('worker-1', 'assignment-1', $timeZone, 'Hospital');
    }
}
