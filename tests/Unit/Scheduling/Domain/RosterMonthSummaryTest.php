<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\RestStreakInsights;
use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterMonth;
use App\Scheduling\Domain\RosterMonthSummary;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ShiftSegment;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\WorkDate;
use App\SharedKernel\Domain\ShiftKind;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RosterMonthSummaryTest extends TestCase
{
    /**
     * The one that would be a real bug: a month where somebody filled in a
     * single week is not a month with 23 days off.
     */
    public function test_days_with_no_information_are_never_counted_as_days_off(): void
    {
        $summary = RosterMonthSummary::of($this->september(), [
            $this->morning('2026-09-01'),
            $this->rest('2026-09-02'),
        ]);

        self::assertSame(1, $summary->workedDays);
        self::assertSame(1, $summary->restDays);
        self::assertSame(28, $summary->unknownDays);
    }

    public function test_nights_are_counted_from_the_hours_actually_worked(): void
    {
        $summary = RosterMonthSummary::of($this->september(), [
            $this->night('2026-09-01'),
            $this->night('2026-09-02'),
            $this->morning('2026-09-03'),
        ]);

        self::assertSame(2, $summary->nightShifts);
        self::assertSame(3, $summary->workedDays);
    }

    public function test_the_longest_run_of_days_off_is_broken_by_an_unknown_day(): void
    {
        $summary = RosterMonthSummary::of($this->september(), [
            $this->rest('2026-09-16'),
            $this->rest('2026-09-17'),
            // 18 is unknown: we do not know that it is free, so the run stops.
            $this->rest('2026-09-19'),
        ]);

        self::assertSame(2, $summary->longestRestStreak);
        self::assertSame('2026-09-16', (string) $summary->longestRestStreakStart);
    }

    public function test_four_days_off_in_a_row_become_an_insight(): void
    {
        $days = array_map(fn (int $day): RosterDay => $this->rest(\sprintf('2026-09-%02d', $day)), [16, 17, 18, 19]);

        $insights = (new RestStreakInsights())->insightsFor($this->september(), $days);

        self::assertCount(1, $insights);
        self::assertSame('Tienes 4 días libres seguidos', $insights[0]->headline);
        self::assertSame('Del 16 al 19 de septiembre.', $insights[0]->detail);
    }

    public function test_two_days_off_are_not_worth_saying_out_loud(): void
    {
        $days = [$this->rest('2026-09-16'), $this->rest('2026-09-17')];

        self::assertSame([], (new RestStreakInsights())->insightsFor($this->september(), $days));
    }

    public function test_days_from_a_neighbouring_month_do_not_leak_into_the_totals(): void
    {
        $summary = RosterMonthSummary::of($this->september(), [
            $this->morning('2026-08-31'),
            $this->morning('2026-09-01'),
            $this->morning('2026-10-01'),
        ]);

        self::assertSame(1, $summary->workedDays);
    }

    private function september(): RosterMonth
    {
        return RosterMonth::fromString('2026-09');
    }

    private function rest(string $date): RosterDay
    {
        return RosterDay::rest('d'.$date, 'a1', WorkDate::fromString($date), RosterSource::MANUAL, $this->now());
    }

    private function morning(string $date): RosterDay
    {
        return $this->working($date, 'Mañana', 'M', '08:00', '15:00', ShiftKind::MORNING);
    }

    private function night(string $date): RosterDay
    {
        return $this->working($date, 'Noche', 'N', '22:00', '08:00', ShiftKind::NIGHT);
    }

    private function working(string $date, string $label, string $code, string $start, string $end, ShiftKind $kind): RosterDay
    {
        $segment = new ShiftSegment('s'.$date, null, $label, $code, ShiftWindow::fromStrings($start, $end), $kind, 0);

        return RosterDay::working('d'.$date, 'a1', WorkDate::fromString($date), [$segment], RosterSource::MANUAL, $this->now());
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-01T09:00:00+00:00');
    }
}
