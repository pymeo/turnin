<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\WorkDate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WorkDate is the foundation every rota calculation stands on, and it does its
 * arithmetic without DateTimeImmutable. These tests are the proof that the
 * integer maths is right where a calendar is easy to get wrong: leap years,
 * month ends and the turn of the year.
 */
final class WorkDateTest extends TestCase
{
    public function test_a_date_round_trips_through_its_day_number(): void
    {
        $date = WorkDate::of(2026, 9, 11);

        self::assertSame('2026-09-11', (string) $date);
        self::assertTrue(WorkDate::fromDayNumber($date->dayNumber())->equals($date));
    }

    #[DataProvider('knownWeekdays')]
    public function test_the_day_of_the_week_matches_the_calendar(string $date, int $isoWeekday): void
    {
        self::assertSame($isoWeekday, WorkDate::fromString($date)->dayOfWeek());
    }

    /** @return iterable<string, array{string, int}> */
    public static function knownWeekdays(): iterable
    {
        yield 'friday' => ['2026-09-11', 5];
        yield 'monday' => ['2026-09-14', 1];
        yield 'sunday' => ['2026-09-13', 7];
        yield 'leap day, a saturday' => ['2028-02-29', 2];
        yield 'new year' => ['2027-01-01', 5];
    }

    #[DataProvider('monthLengths')]
    public function test_month_lengths_include_february(int $year, int $month, int $length): void
    {
        self::assertSame($length, WorkDate::daysIn($year, $month));
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function monthLengths(): iterable
    {
        yield 'september has thirty' => [2026, 9, 30];
        yield 'october has thirty-one' => [2026, 10, 31];
        yield 'february in a common year' => [2026, 2, 28];
        yield 'february in a leap year' => [2028, 2, 29];
        yield 'february in 2100, which is not a leap year' => [2100, 2, 28];
        yield 'february in 2000, which is' => [2000, 2, 29];
    }

    public function test_adding_days_crosses_months_and_years(): void
    {
        self::assertSame('2026-10-01', (string) WorkDate::fromString('2026-09-30')->plusDays(1));
        self::assertSame('2027-01-01', (string) WorkDate::fromString('2026-12-31')->plusDays(1));
        self::assertSame('2028-03-01', (string) WorkDate::fromString('2028-02-28')->plusDays(2));
    }

    public function test_the_distance_between_two_dates_is_symmetric(): void
    {
        $from = WorkDate::fromString('2026-09-14');
        $to = WorkDate::fromString('2026-12-31');

        self::assertSame(108, $from->daysUntil($to));
        self::assertSame(-108, $to->daysUntil($from));
    }

    public function test_a_date_that_does_not_exist_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkDate::of(2026, 2, 29);
    }

    public function test_a_malformed_string_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkDate::fromString('11/09/2026');
    }

    public function test_month_boundaries(): void
    {
        $date = WorkDate::fromString('2026-02-14');

        self::assertSame('2026-02-01', (string) $date->firstOfMonth());
        self::assertSame('2026-02-28', (string) $date->lastOfMonth());
        self::assertSame('2026-02', (string) $date->month());
    }
}
