<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\ShiftColor;
use App\SharedKernel\Domain\ShiftKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Colour is presentation and nothing else. These tests pin the two things the
 * interface depends on: that the palette is a closed, named set, and that an
 * unknown key never lands a preset on grey by accident.
 */
final class ShiftColorTest extends TestCase
{
    public function test_the_palette_is_the_whole_enum_and_every_tone_has_a_name(): void
    {
        $palette = ShiftColor::palette();

        self::assertSame(ShiftColor::cases(), $palette);
        self::assertCount(10, $palette);

        $labels = array_map(static fn (ShiftColor $color): string => $color->label(), $palette);
        self::assertSame($labels, array_unique($labels), 'Two tones with the same name are indistinguishable in the picker.');
        foreach ($labels as $label) {
            self::assertNotSame('', trim($label));
        }
    }

    #[DataProvider('suggestions')]
    public function test_each_kind_suggests_a_tone(ShiftKind $kind, ShiftColor $expected): void
    {
        self::assertSame($expected, ShiftColor::suggestedFor($kind));
    }

    /** @return iterable<string, array{ShiftKind, ShiftColor}> */
    public static function suggestions(): iterable
    {
        yield 'morning' => [ShiftKind::MORNING, ShiftColor::AMBER];
        yield 'evening' => [ShiftKind::EVENING, ShiftColor::ORANGE];
        yield 'night' => [ShiftKind::NIGHT, ShiftColor::BLUE];
        yield 'long night' => [ShiftKind::LONG_NIGHT, ShiftColor::BLUE];
        yield 'long day' => [ShiftKind::LONG_DAY, ShiftColor::EMERALD];
        yield 'on call' => [ShiftKind::ON_CALL, ShiftColor::VIOLET];
        yield 'other' => [ShiftKind::OTHER, ShiftColor::SLATE];
    }

    public function test_a_chosen_key_wins_over_the_suggestion(): void
    {
        self::assertSame(ShiftColor::CYAN, ShiftColor::fromKeyOrSuggestion('cyan', ShiftKind::MORNING));
    }

    /** A dropped or malformed key must not turn a morning shift grey. */
    public function test_an_unknown_key_falls_back_to_the_tone_for_the_kind(): void
    {
        self::assertSame(ShiftColor::AMBER, ShiftColor::fromKeyOrSuggestion(null, ShiftKind::MORNING));
        self::assertSame(ShiftColor::AMBER, ShiftColor::fromKeyOrSuggestion('', ShiftKind::MORNING));
        self::assertSame(ShiftColor::AMBER, ShiftColor::fromKeyOrSuggestion('bg-amber-300', ShiftKind::MORNING));
        self::assertSame(ShiftColor::VIOLET, ShiftColor::fromKeyOrSuggestion('chartreuse', ShiftKind::ON_CALL));
    }
}
