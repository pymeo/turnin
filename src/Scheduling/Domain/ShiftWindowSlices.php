<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/** Deterministic minute-precision proposals; the result remains a plain window. */
final readonly class ShiftWindowSlices
{
    public static function twelveHoursFrom(LocalTime $start): ShiftWindow
    {
        return ShiftWindow::between($start, self::atAbsoluteMinute($start->minuteOfDay() + 720));
    }

    /** @return array{ShiftWindow, ShiftWindow} */
    public static function halves(ShiftWindow $window): array
    {
        $parts = self::parts($window, 2);

        return [$parts[0], $parts[1]];
    }

    /** @return array{ShiftWindow, ShiftWindow, ShiftWindow} */
    public static function thirds(ShiftWindow $window): array
    {
        $parts = self::parts($window, 3);

        return [$parts[0], $parts[1], $parts[2]];
    }

    /** @return list<ShiftWindow> */
    private static function parts(ShiftWindow $window, int $count): array
    {
        $start = $window->start->minuteOfDay();
        $duration = $window->durationInMinutes();
        $boundaries = [$start];
        for ($part = 1; $part < $count; ++$part) {
            // Earlier parts receive any remainder. This is deterministic and
            // never introduces seconds; the UI still lets the worker edit it.
            $boundaries[] = $start + (int) ceil($duration * $part / $count);
        }
        $boundaries[] = $start + $duration;

        $parts = [];
        for ($part = 0; $part < $count; ++$part) {
            $parts[] = ShiftWindow::between(self::atAbsoluteMinute($boundaries[$part]), self::atAbsoluteMinute($boundaries[$part + 1]));
        }

        return $parts;
    }

    private static function atAbsoluteMinute(int $minute): LocalTime
    {
        $minute %= 24 * 60;

        return LocalTime::of(intdiv($minute, 60), $minute % 60);
    }
}
