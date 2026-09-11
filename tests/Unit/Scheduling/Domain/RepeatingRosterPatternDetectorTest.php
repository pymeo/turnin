<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\RepeatingRosterPatternDetector;
use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ShiftSegment;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\WorkDate;
use App\SharedKernel\Domain\ShiftKind;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Suggesting a rotation is only worth doing if the suggestion is right. These
 * tests pin the strictness as much as the detection: exact repetition, two
 * whole cycles, no gaps, or nothing is offered at all.
 */
final class RepeatingRosterPatternDetectorTest extends TestCase
{
    public function test_it_spots_a_nine_day_rotation_after_two_cycles(): void
    {
        $detected = $this->detect('MMTTNNLLL MMTTNNLLL', '2026-09-01');

        self::assertNotNull($detected);
        self::assertSame(9, $detected->length());
        self::assertSame('M · M · T · T · N · N · L · L · L', $detected->sequence());
        self::assertSame(2, $detected->observedCycles);
        self::assertSame('2026-09-19', (string) $detected->repeatsFrom);
    }

    public function test_it_says_nothing_after_a_single_cycle(): void
    {
        self::assertNull($this->detect('MMTTNNLLL', '2026-09-01'));
    }

    public function test_a_gap_in_the_middle_breaks_the_run(): void
    {
        $days = [
            ...$this->daysFrom('MMTTNNLLL', '2026-09-01'),
            // 10 September is missing, so the second cycle is a different run.
            ...$this->daysFrom('MMTTNNLLL', '2026-09-11'),
        ];

        self::assertNull((new RepeatingRosterPatternDetector())->detect($days));
    }

    public function test_a_long_stretch_of_identical_days_is_not_a_rotation(): void
    {
        self::assertNull($this->detect('MMMMMMMMMM', '2026-09-01'));
    }

    public function test_it_prefers_the_shortest_cycle_that_explains_the_run(): void
    {
        $detected = $this->detect('MNMNMNMN', '2026-09-01');

        self::assertNotNull($detected);
        self::assertSame(2, $detected->length());
        self::assertSame(4, $detected->observedCycles);
    }

    public function test_an_almost_repetition_is_not_offered(): void
    {
        self::assertNull($this->detect('MMTTNNLLL MMTTNNLLM', '2026-09-01'));
    }

    private function detect(string $codes, string $from): ?\App\Scheduling\Domain\DetectedRosterPattern
    {
        return (new RepeatingRosterPatternDetector())->detect($this->daysFrom($codes, $from));
    }

    /** @return list<RosterDay> */
    private function daysFrom(string $codes, string $from): array
    {
        $date = WorkDate::fromString($from);
        $days = [];
        $offset = 0;

        foreach (str_split(str_replace(' ', '', $codes)) as $code) {
            $days[] = $this->day($date->plusDays($offset), $code);
            ++$offset;
        }

        return $days;
    }

    private function day(WorkDate $date, string $code): RosterDay
    {
        $now = new DateTimeImmutable('2026-09-01T09:00:00+00:00');
        if ('L' === $code) {
            return RosterDay::rest('d'.$date, 'a1', $date, RosterSource::MANUAL, $now);
        }

        $presets = [
            'M' => ['Mañana', '08:00', '15:00', ShiftKind::MORNING],
            'T' => ['Tarde', '15:00', '22:00', ShiftKind::EVENING],
            'N' => ['Noche', '22:00', '08:00', ShiftKind::NIGHT],
        ];
        [$label, $start, $end, $kind] = $presets[$code];
        $segment = new ShiftSegment('s'.$date, strtolower($code), $label, $code, ShiftWindow::fromStrings($start, $end), $kind, 0);

        return RosterDay::working('d'.$date, 'a1', $date, [$segment], RosterSource::MANUAL, $now);
    }
}
