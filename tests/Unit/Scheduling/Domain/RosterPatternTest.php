<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\DraftIntent;
use App\Scheduling\Domain\PatternSlot;
use App\Scheduling\Domain\RosterPattern;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ScheduleDraftEntry;
use App\Scheduling\Domain\ShiftPreset;
use App\Scheduling\Domain\ShiftPresetResolver;
use App\Scheduling\Domain\ShiftWindow;
use App\Scheduling\Domain\WorkDate;
use App\SharedKernel\Domain\ShiftKind;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * A rotation laid over a range. The cases that matter are the ones where a
 * naive implementation drifts: months of different lengths, February, and the
 * turn of the year.
 */
final class RosterPatternTest extends TestCase
{
    private const NOW = '2026-09-11T09:00:00+00:00';

    public function test_it_repeats_the_sequence_for_at_least_two_cycles(): void
    {
        $draft = $this->mmttnnlll()->expand(WorkDate::fromString('2026-09-01'), WorkDate::fromString('2026-09-18'), $this->presets(), RosterSource::PATTERN);

        self::assertSame('M M T T N N L L L M M T T N N L L L', $this->codes($draft->entries));
    }

    public function test_it_is_named_for_its_length_when_the_worker_does_not_name_it(): void
    {
        self::assertSame('Patrón de 9 días', $this->mmttnnlll()->name());
    }

    public function test_it_can_be_renamed_afterwards(): void
    {
        $pattern = $this->mmttnnlll();
        $pattern->rename('Mi rotación', new DateTimeImmutable(self::NOW));

        self::assertSame('Mi rotación', $pattern->name());
    }

    public function test_a_thirty_day_month_and_a_thirty_one_day_month_stay_in_phase(): void
    {
        $draft = $this->mmttnnlll()->expand(WorkDate::fromString('2026-09-28'), WorkDate::fromString('2026-10-03'), $this->presets(), RosterSource::PATTERN);

        // Slot 1..6 across the 30 September / 1 October boundary: the rotation
        // does not care how long the month was.
        self::assertSame('M M T T N N', $this->codes($draft->entries));
        self::assertSame('2026-09-28', (string) $draft->entries[0]->date);
        self::assertSame('2026-10-03', (string) $draft->entries[5]->date);
    }

    public function test_february_in_a_leap_year_is_covered_day_by_day(): void
    {
        $draft = $this->mmttnnlll()->expand(WorkDate::fromString('2028-02-01'), WorkDate::fromString('2028-02-29'), $this->presets(), RosterSource::PATTERN);

        self::assertCount(29, $draft->entries);
        self::assertSame('2028-02-29', (string) $draft->entries[28]->date);
    }

    public function test_it_crosses_the_turn_of_the_year(): void
    {
        $draft = $this->mmttnnlll()->expand(WorkDate::fromString('2026-12-29'), WorkDate::fromString('2027-01-04'), $this->presets(), RosterSource::PATTERN);

        self::assertCount(7, $draft->entries);
        self::assertSame('2027-01-04', (string) $draft->entries[6]->date);
    }

    public function test_a_quarter_of_rotation_reports_the_numbers_the_preview_shows(): void
    {
        $draft = $this->mmttnnlll()->expand(WorkDate::fromString('2026-09-14'), WorkDate::fromString('2026-12-31'), $this->presets(), RosterSource::PATTERN);

        $shifts = array_filter($draft->entries, static fn (ScheduleDraftEntry $entry): bool => DraftIntent::WORK === $entry->intent);
        $rests = array_filter($draft->entries, static fn (ScheduleDraftEntry $entry): bool => DraftIntent::REST === $entry->intent);

        self::assertCount(109, $draft->entries);
        self::assertCount(73, $shifts);
        self::assertCount(36, $rests);
    }

    public function test_a_rotation_whose_preset_disappeared_is_flagged_rather_than_guessed(): void
    {
        $pattern = RosterPattern::create('pattern-1', 'assignment-1', null, [PatternSlot::shift(1, 'gone'), PatternSlot::rest(2)], new DateTimeImmutable(self::NOW));

        $draft = $pattern->expand(WorkDate::fromString('2026-09-01'), WorkDate::fromString('2026-09-02'), $this->presets(), RosterSource::PATTERN);

        self::assertCount(1, $draft->unresolvedEntries());
        self::assertCount(1, $draft->applicableEntries());
    }

    public function test_an_end_before_the_start_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->mmttnnlll()->expand(WorkDate::fromString('2026-09-10'), WorkDate::fromString('2026-09-01'), $this->presets(), RosterSource::PATTERN);
    }

    private function mmttnnlll(): RosterPattern
    {
        return RosterPattern::create('pattern-1', 'assignment-1', null, [
            PatternSlot::shift(1, 'morning'),
            PatternSlot::shift(2, 'morning'),
            PatternSlot::shift(3, 'evening'),
            PatternSlot::shift(4, 'evening'),
            PatternSlot::shift(5, 'night'),
            PatternSlot::shift(6, 'night'),
            PatternSlot::rest(7),
            PatternSlot::rest(8),
            PatternSlot::rest(9),
        ], new DateTimeImmutable(self::NOW));
    }

    private function presets(): ShiftPresetResolver
    {
        $now = new DateTimeImmutable(self::NOW);

        return new ShiftPresetResolver([
            ShiftPreset::create('morning', 'assignment-1', 'Mañana', 'M', ShiftWindow::fromStrings('08:00', '15:00'), ShiftKind::MORNING, [], 1, $now),
            ShiftPreset::create('evening', 'assignment-1', 'Tarde', 'T', ShiftWindow::fromStrings('15:00', '22:00'), ShiftKind::EVENING, [], 2, $now),
            ShiftPreset::create('night', 'assignment-1', 'Noche', 'N', ShiftWindow::fromStrings('22:00', '08:00'), ShiftKind::NIGHT, [], 3, $now),
        ]);
    }

    /** @param list<ScheduleDraftEntry> $entries */
    private function codes(array $entries): string
    {
        return implode(' ', array_map(static fn (ScheduleDraftEntry $entry): string => $entry->abbreviation(), $entries));
    }
}
