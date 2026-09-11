<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterDayState;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ShiftKind;
use App\Scheduling\Domain\ShiftPreset;
use App\Scheduling\Domain\ShiftSegment;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\WorkDate;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RosterDayTest extends TestCase
{
    private const NOW = '2026-09-11T09:00:00+00:00';

    public function test_a_rest_day_carries_no_segments(): void
    {
        $day = RosterDay::rest('day-1', 'assignment-1', WorkDate::fromString('2026-09-16'), RosterSource::MANUAL, $this->now());

        self::assertSame(RosterDayState::REST, $day->state());
        self::assertSame([], $day->segments());
        self::assertSame('L', $day->abbreviation());
    }

    public function test_a_rest_day_with_a_segment_is_impossible(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rest day cannot carry shift segments');

        RosterDay::restore('day-1', 'assignment-1', WorkDate::fromString('2026-09-16'), RosterDayState::REST, [$this->segment()], RosterSource::MANUAL, $this->now(), $this->now());
    }

    public function test_a_working_day_needs_at_least_one_segment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('working day needs at least one shift segment');

        RosterDay::working('day-1', 'assignment-1', WorkDate::fromString('2026-09-16'), [], RosterSource::MANUAL, $this->now());
    }

    public function test_marking_a_working_day_as_rest_drops_its_segments(): void
    {
        $day = RosterDay::working('day-1', 'assignment-1', WorkDate::fromString('2026-09-16'), [$this->segment()], RosterSource::MANUAL, $this->now());

        $day->markRest(RosterSource::VOICE, $this->now());

        self::assertTrue($day->isRest());
        self::assertSame([], $day->segments());
        self::assertSame(RosterSource::VOICE, $day->source());
    }

    public function test_a_day_can_hold_a_split_shift(): void
    {
        $morning = new ShiftSegment('s1', null, 'Mañana', 'M', ShiftWindow::fromStrings('08:00', '12:00'), ShiftKind::MORNING, 0);
        $evening = new ShiftSegment('s2', null, 'Tarde', 'T', ShiftWindow::fromStrings('16:00', '20:00'), ShiftKind::EVENING, 1);

        $day = RosterDay::working('day-1', 'assignment-1', WorkDate::fromString('2026-09-16'), [$morning, $evening], RosterSource::MANUAL, $this->now());

        self::assertCount(2, $day->segments());
        self::assertSame('M', $day->abbreviation());
    }

    public function test_overlapping_segments_are_rejected(): void
    {
        $first = new ShiftSegment('s1', null, 'Mañana', 'M', ShiftWindow::fromStrings('08:00', '15:00'), ShiftKind::MORNING, 0);
        $second = new ShiftSegment('s2', null, 'Guardia', 'G', ShiftWindow::fromStrings('14:00', '20:00'), ShiftKind::ON_CALL, 1);

        $this->expectException(InvalidArgumentException::class);
        RosterDay::working('day-1', 'assignment-1', WorkDate::fromString('2026-09-16'), [$first, $second], RosterSource::MANUAL, $this->now());
    }

    /**
     * The point of the snapshot: editing the preset next month must not rewrite
     * what somebody actually worked last March.
     */
    public function test_a_segment_keeps_the_hours_it_was_created_with_when_the_preset_changes(): void
    {
        $preset = ShiftPreset::create('preset-1', 'assignment-1', 'Mañana', 'M', ShiftWindow::fromStrings('08:00', '15:00'), ShiftKind::MORNING, [], 1, $this->now());
        $segment = ShiftSegment::fromPreset('segment-1', $preset, 0);

        $preset->reshape('Mañana', 'M', ShiftWindow::fromStrings('07:30', '14:30'), ShiftKind::MORNING, [], $this->now());

        self::assertSame('08:00', (string) $segment->window->start);
        self::assertSame('15:00', (string) $segment->window->end);
        self::assertSame('07:30', (string) $preset->window()->start);
    }

    public function test_a_night_shift_is_recognised_as_night_work(): void
    {
        $night = new ShiftSegment('s1', null, 'Noche', 'N', ShiftWindow::fromStrings('22:00', '08:00'), ShiftKind::NIGHT, 0);
        $day = RosterDay::working('day-1', 'assignment-1', WorkDate::fromString('2026-09-16'), [$night], RosterSource::MANUAL, $this->now());

        self::assertTrue($day->coversNightHours());
        self::assertTrue($night->endsNextDay());
    }

    private function segment(): ShiftSegment
    {
        return new ShiftSegment('segment-1', null, 'Mañana', 'M', ShiftWindow::fromStrings('08:00', '15:00'), ShiftKind::MORNING, 0);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
