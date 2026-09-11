<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\LocalTime;
use App\Scheduling\Domain\ShiftWindow;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ShiftWindowTest extends TestCase
{
    public function test_a_morning_shift_ends_the_same_day(): void
    {
        $window = ShiftWindow::fromStrings('08:00', '15:00');

        self::assertFalse($window->endsNextDay());
        self::assertSame(420, $window->durationInMinutes());
        self::assertFalse($window->coversNightHours());
    }

    public function test_a_night_shift_crosses_midnight_without_storing_a_flag(): void
    {
        $window = ShiftWindow::fromStrings('22:00', '08:00');

        self::assertTrue($window->endsNextDay());
        self::assertSame(600, $window->durationInMinutes());
        self::assertTrue($window->coversNightHours());
    }

    public function test_an_identical_start_and_end_is_a_twenty_four_hour_shift(): void
    {
        $window = ShiftWindow::fromStrings('08:00', '08:00');

        self::assertTrue($window->endsNextDay());
        self::assertSame(1440, $window->durationInMinutes());
        self::assertTrue($window->coversNightHours());
    }

    public function test_an_evening_shift_that_ends_at_ten_does_not_count_as_night_work(): void
    {
        self::assertFalse(ShiftWindow::fromStrings('15:00', '22:00')->coversNightHours());
    }

    public function test_windows_that_share_a_minute_overlap(): void
    {
        $morning = ShiftWindow::fromStrings('08:00', '15:00');

        self::assertTrue($morning->overlaps(ShiftWindow::fromStrings('14:00', '20:00')));
        self::assertFalse($morning->overlaps(ShiftWindow::fromStrings('15:00', '22:00')));
    }

    public function test_a_time_outside_the_clock_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LocalTime::of(24, 0);
    }

    public function test_seconds_from_the_database_are_accepted(): void
    {
        self::assertSame('22:00', (string) LocalTime::fromString('22:00:00'));
    }
}
