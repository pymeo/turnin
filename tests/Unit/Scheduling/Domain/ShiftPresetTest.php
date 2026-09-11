<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\ShiftKind;
use App\Scheduling\Domain\ShiftPreset;
use App\Scheduling\Domain\ShiftPresetResolver;
use App\Scheduling\Domain\ShiftWindow;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ShiftPresetTest extends TestCase
{
    public function test_an_abbreviation_longer_than_three_characters_would_break_the_cell(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('abreviatura');

        $this->preset('Mañana', 'MAÑA');
    }

    public function test_a_three_character_abbreviation_is_allowed(): void
    {
        self::assertSame('12D', $this->preset('Turno de 12 h', '12D')->abbreviation());
    }

    public function test_a_retired_preset_is_kept_so_old_shifts_stay_readable(): void
    {
        $preset = $this->preset('Guardia', 'G');
        $preset->deactivate(new DateTimeImmutable('2026-09-11T09:00:00+00:00'));

        self::assertFalse($preset->active());
        self::assertSame('Guardia', $preset->name());
    }

    public function test_spoken_forms_include_the_name_the_abbreviation_and_the_aliases(): void
    {
        $preset = ShiftPreset::create('p1', 'a1', 'Guardia 24h', 'G', ShiftWindow::fromStrings('08:00', '08:00'), ShiftKind::ON_CALL, ['guardia', '24 horas'], 1, new DateTimeImmutable('2026-09-11T09:00:00+00:00'));

        self::assertSame(['Guardia 24h', 'G', 'guardia', '24 horas'], $preset->spokenForms());
    }

    public function test_a_resolver_matches_a_custom_alias_case_and_accent_insensitively(): void
    {
        $guard = ShiftPreset::create('p1', 'a1', 'Guardia 24h', 'G', ShiftWindow::fromStrings('08:00', '08:00'), ShiftKind::ON_CALL, ['guardia', '24 horas'], 1, new DateTimeImmutable('2026-09-11T09:00:00+00:00'));
        $morning = ShiftPreset::create('p2', 'a1', 'Mañana', 'M', ShiftWindow::fromStrings('08:00', '15:00'), ShiftKind::MORNING, ['turno de mañana'], 2, new DateTimeImmutable('2026-09-11T09:00:00+00:00'));

        $resolver = new ShiftPresetResolver([$guard, $morning]);

        self::assertSame('p1', $resolver->bySpokenForm('GUARDIA')?->id());
        self::assertSame('p1', $resolver->bySpokenForm('24 Horas')?->id());
        self::assertSame('p2', $resolver->bySpokenForm('  Turno de Mañana ')?->id());
        self::assertSame('p2', $resolver->bySpokenForm('M')?->id());
        self::assertNull($resolver->bySpokenForm('vacaciones'));
    }

    public function test_a_retired_preset_is_not_offered_but_can_still_be_resolved_by_id(): void
    {
        $retired = $this->preset('Refuerzo', 'R');
        $retired->deactivate(new DateTimeImmutable('2026-09-11T09:00:00+00:00'));

        $resolver = new ShiftPresetResolver([$retired]);

        self::assertTrue($resolver->isEmpty());
        self::assertNull($resolver->bySpokenForm('refuerzo'));
        self::assertNotNull($resolver->byId($retired->id()));
    }

    private function preset(string $name, string $abbreviation): ShiftPreset
    {
        return ShiftPreset::create('p1', 'a1', $name, $abbreviation, ShiftWindow::fromStrings('08:00', '15:00'), ShiftKind::MORNING, [], 1, new DateTimeImmutable('2026-09-11T09:00:00+00:00'));
    }
}
