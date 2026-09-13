<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Domain;

use App\Swap\Domain\RosteredDay;
use App\Swap\Domain\RosteredDayState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RosteredDayDurationTest extends TestCase
{
    /** @return iterable<string, array{string, string, int, string}> */
    public static function realWindows(): iterable
    {
        yield 'standard eight hours' => ['08:00', '16:00', 480, '8 h'];
        yield 'custom twelve hours' => ['08:00', '20:00', 720, '12 h'];
        yield 'custom overnight' => ['19:00', '07:00', 720, '12 h'];
        yield 'overnight long shift' => ['20:00', '08:00', 720, '12 h'];
        yield 'whole day guard' => ['08:00', '08:00', 1440, '24 h'];
        yield 'minutes are not hidden' => ['08:15', '16:45', 510, '8 h 30 min'];
    }

    #[DataProvider('realWindows')]
    public function test_duration_is_derived_from_real_hours(string $start, string $end, int $minutes, string $label): void
    {
        $day = new RosteredDay('assignment', '2026-09-20', RosteredDayState::WORKING, 'day', 'Custom', 'C', $start, $end, $end <= $start);

        self::assertSame($minutes, $day->durationMinutes());
        self::assertSame($label, $day->durationLabel());
    }
}
