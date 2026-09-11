<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\DateExpressionParser;
use App\Scheduling\Domain\RosterMonth;
use App\Scheduling\Domain\RosterSource;
use App\Scheduling\Domain\ScheduleDraftEntry;
use App\Scheduling\Domain\ScheduleTextParser;
use App\Scheduling\Domain\ShiftKind;
use App\Scheduling\Domain\ShiftPreset;
use App\Scheduling\Domain\ShiftPresetResolver;
use App\Scheduling\Domain\ShiftWindow;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The grammar Turnin actually accepts. These tests are the specification: if a
 * phrase is not here, we do not claim to understand it, and the parser says so
 * rather than guessing.
 */
final class ScheduleTextParserTest extends TestCase
{
    private const MONTH = '2026-09';

    #[DataProvider('phrases')]
    public function test_it_reads_the_supported_grammar(string $text, string $expected): void
    {
        $reading = $this->parse($text);

        self::assertSame($expected, $this->describe($reading->draft->entries));
        self::assertSame([], $reading->unrecognized, 'Nothing should be left over: '.implode(' | ', $reading->unrecognized));
    }

    /** @return iterable<string, array{string, string}> */
    public static function phrases(): iterable
    {
        yield 'one day' => ['1 mañana', '1:M'];
        yield 'two joined by y' => ['1 y 2 mañana', '1:M 2:M'];
        yield 'a list' => ['1, 2 y 3 mañana', '1:M 2:M 3:M'];
        yield 'a spelled range' => ['del 1 al 4 mañana', '1:M 2:M 3:M 4:M'];
        yield 'a dashed range' => ['1-4 mañana', '1:M 2:M 3:M 4:M'];
        yield 'an evening' => ['5 tarde', '5:T'];
        yield 'two evenings' => ['5 y 6 tarde', '5:T 6:T'];
        yield 'a night' => ['7 noche', '7:N'];
        yield 'one day off' => ['8 libre', '8:L'];
        yield 'two days off' => ['8 y 9 libres', '8:L 9:L'];
        yield 'a range off' => ['del 10 al 12 libre', '10:L 11:L 12:L'];
        yield 'a whole week' => ['1 mañana, 2 tarde, 3 noche, 4 libre', '1:M 2:T 3:N 4:L'];
        yield 'the full example' => ['1 y 2 mañana, 3 y 4 tarde, 5 noche, 6 y 7 libres', '1:M 2:M 3:T 4:T 5:N 6:L 7:L'];
        yield 'spelled numbers, as dictation produces them' => ['uno y dos mañana, tres tarde, cuatro noche', '1:M 2:M 3:T 4:N'];
        yield 'a long form' => ['del 1 al 3 turno de mañana', '1:M 2:M 3:M'];
        yield 'descanso as a synonym' => ['15 descanso', '15:L'];
        yield 'a day in the twenties' => ['veintitrés noche', '23:N'];
        yield 'a spelled day at the end of the month' => ['treinta libre', '30:L'];
    }

    public function test_the_thirty_first_is_read_as_one_number_in_a_month_that_has_one(): void
    {
        $reading = $this->parse('treinta y uno libre', RosterMonth::fromString('2026-10'));

        self::assertSame('31:L', $this->describe($reading->draft->entries));
        self::assertSame([], $reading->unrecognized);
    }

    public function test_a_later_clause_wins_over_an_earlier_one_for_the_same_day(): void
    {
        $reading = $this->parse('1 y 2 mañana, 2 tarde');

        self::assertSame('1:M 2:T', $this->describe($reading->draft->entries));
    }

    public function test_a_custom_preset_alias_is_understood(): void
    {
        $reading = $this->parse('del 4 al 5 guardia');

        self::assertSame('4:G 5:G', $this->describe($reading->draft->entries));
    }

    public function test_a_day_that_does_not_exist_in_the_month_is_reported_not_invented(): void
    {
        $reading = $this->parse('31 mañana', RosterMonth::fromString('2026-02'));

        self::assertTrue($reading->draft->isEmpty());
        self::assertSame(['El día 31 no existe en 2026-02.'], $reading->unrecognized);
    }

    public function test_a_fragment_it_cannot_place_is_handed_back(): void
    {
        $reading = $this->parse('1 mañana y luego lo que salga');

        self::assertSame('1:M', $this->describe($reading->draft->entries));
        self::assertSame(['y luego lo que salga'], $reading->unrecognized);
    }

    public function test_text_with_no_shift_at_all_understands_nothing(): void
    {
        $reading = $this->parse('hola qué tal');

        self::assertTrue($reading->understoodNothing());
        self::assertSame(['hola que tal'], $reading->unrecognized);
    }

    public function test_a_dictated_rotation_becomes_a_pattern_rather_than_days(): void
    {
        $reading = $this->parse('mi patrón es mañana mañana tarde tarde noche noche y tres libres');

        self::assertNotNull($reading->pattern);
        self::assertSame(9, $reading->pattern->length());
        self::assertSame('M · M · T · T · N · N · L · L · L', $reading->pattern->sequence());
        self::assertTrue($reading->draft->isEmpty(), 'A rotation is not a set of dates until the worker picks a range.');
    }

    public function test_a_rotation_can_use_counts_throughout(): void
    {
        $reading = $this->parse('mi rotación es dos mañanas dos tardes dos noches y tres libres');

        self::assertNotNull($reading->pattern);
        self::assertSame('M · M · T · T · N · N · L · L · L', $reading->pattern->sequence());
    }

    public function test_empty_text_is_not_an_error(): void
    {
        $reading = $this->parse('   ');

        self::assertTrue($reading->understoodNothing());
        self::assertSame([], $reading->unrecognized);
    }

    private function parse(string $text, ?RosterMonth $month = null): \App\Scheduling\Domain\ScheduleTextReading
    {
        return (new ScheduleTextParser(new DateExpressionParser()))->parse(
            $text,
            $month ?? RosterMonth::fromString(self::MONTH),
            $this->presets(),
            RosterSource::TEXT,
        );
    }

    private function presets(): ShiftPresetResolver
    {
        $now = new DateTimeImmutable('2026-09-11T09:00:00+00:00');

        return new ShiftPresetResolver([
            ShiftPreset::create('morning', 'a1', 'Mañana', 'M', ShiftWindow::fromStrings('08:00', '15:00'), ShiftKind::MORNING, ['mananas', 'turno de manana'], 1, $now),
            ShiftPreset::create('evening', 'a1', 'Tarde', 'T', ShiftWindow::fromStrings('15:00', '22:00'), ShiftKind::EVENING, ['tardes', 'turno de tarde'], 2, $now),
            ShiftPreset::create('night', 'a1', 'Noche', 'N', ShiftWindow::fromStrings('22:00', '08:00'), ShiftKind::NIGHT, ['noches', 'turno de noche'], 3, $now),
            ShiftPreset::create('guard', 'a1', 'Guardia 24h', 'G', ShiftWindow::fromStrings('08:00', '08:00'), ShiftKind::ON_CALL, ['guardia', '24 horas'], 4, $now),
        ]);
    }

    /** @param list<ScheduleDraftEntry> $entries */
    private function describe(array $entries): string
    {
        return implode(' ', array_map(static fn (ScheduleDraftEntry $entry): string => $entry->date->day.':'.$entry->abbreviation(), $entries));
    }
}
