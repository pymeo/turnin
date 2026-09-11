<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\LocalTime;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\ShiftWindowSlices;
use PHPUnit\Framework\TestCase;

final class ShiftWindowSlicesTest extends TestCase
{
    public function test_twelve_hour_windows_work_by_day_and_overnight(): void
    {
        self::assertSame('08:00–20:00', (string) ShiftWindowSlices::twelveHoursFrom(LocalTime::fromString('08:00')));
        self::assertSame('20:00–08:00', (string) ShiftWindowSlices::twelveHoursFrom(LocalTime::fromString('20:00')));
        self::assertSame(720, ShiftWindowSlices::twelveHoursFrom(LocalTime::fromString('20:00'))->durationInMinutes());
    }

    public function test_equal_hours_are_a_twenty_four_hour_shift(): void
    {
        self::assertSame(1440, ShiftWindow::fromStrings('08:00', '08:00')->durationInMinutes());
    }

    public function test_halves_and_thirds_are_plain_minute_precision_windows(): void
    {
        [$first, $second] = ShiftWindowSlices::halves(ShiftWindow::fromStrings('07:00', '15:00'));
        self::assertSame('07:00–11:00', (string) $first);
        self::assertSame('11:00–15:00', (string) $second);
        self::assertSame(['07:00–09:40', '09:40–12:20', '12:20–15:00'], array_map('strval', ShiftWindowSlices::thirds(ShiftWindow::fromStrings('07:00', '15:00'))));
    }
}
