<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\ShiftInterval;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\WorkDate;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ShiftIntervalTest extends TestCase
{
    public function test_an_overnight_shift_overlaps_the_next_work_date(): void
    {
        $zone = new DateTimeZone('Europe/Madrid');
        $night = ShiftInterval::materialize(WorkDate::fromString('2026-09-15'), ShiftWindow::fromStrings('22:00', '08:00'), $zone);
        $morning = ShiftInterval::materialize(WorkDate::fromString('2026-09-16'), ShiftWindow::fromStrings('07:00', '15:00'), $zone);

        self::assertTrue($night->overlaps($morning));
        self::assertSame(600, $night->durationInMinutes());
    }

    public function test_materialization_compares_real_instants_across_time_zones(): void
    {
        $madrid = ShiftInterval::materialize(WorkDate::fromString('2026-09-15'), ShiftWindow::fromStrings('08:00', '15:00'), new DateTimeZone('Europe/Madrid'));
        $canary = ShiftInterval::materialize(WorkDate::fromString('2026-09-15'), ShiftWindow::fromStrings('08:00', '15:00'), new DateTimeZone('Atlantic/Canary'));

        self::assertNotEquals($madrid->startsAt, $canary->startsAt);
        self::assertSame(60 * 60, $canary->startsAt->getTimestamp() - $madrid->startsAt->getTimestamp());
    }

    public function test_dst_changes_real_duration_without_corrupting_local_hours(): void
    {
        $interval = ShiftInterval::materialize(WorkDate::fromString('2026-03-28'), ShiftWindow::fromStrings('22:00', '08:00'), new DateTimeZone('Europe/Madrid'));

        self::assertSame(540, $interval->durationInMinutes());
        self::assertSame('22:00', $interval->startsAt->format('H:i'));
        self::assertSame('08:00', $interval->endsAt->format('H:i'));
    }
}
