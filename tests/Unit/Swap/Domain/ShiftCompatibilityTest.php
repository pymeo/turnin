<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Domain;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Domain\RosteredShift;
use App\Swap\Domain\ShiftCompatibility;
use App\Swap\Domain\ShiftObstacle;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShiftCompatibilityTest extends TestCase
{
    public function test_a_free_colleague_can_cover_without_declaring_availability(): void
    {
        $result = ShiftCompatibility::assess([$this->shift('target', '2026-09-20', '08:00', '20:00')], [], true, $this->now());

        self::assertTrue($result->compatible);
        self::assertNull($result->obstacle);
    }

    #[DataProvider('overlaps')]
    public function test_real_overlap_blocks_even_when_only_part_of_the_shift_collides(string $ownStart, string $ownEnd): void
    {
        $target = $this->shift('target', '2026-09-20', '08:00', '20:00');
        $own = $this->shift('own', '2026-09-20', $ownStart, $ownEnd);

        $result = ShiftCompatibility::assess([$target], [$own], true, $this->now());

        self::assertFalse($result->compatible);
        self::assertSame(ShiftObstacle::SHIFT_OVERLAP, $result->obstacle);
    }

    /** @return iterable<string, array{string, string}> */
    public static function overlaps(): iterable
    {
        yield 'exact' => ['08:00', '20:00'];
        yield 'starts before' => ['07:00', '09:00'];
        yield 'ends after' => ['19:00', '22:00'];
        yield 'inside' => ['12:00', '14:00'];
    }

    public function test_same_calendar_date_does_not_block_non_overlapping_intervals(): void
    {
        $morning = $this->shift('own', '2026-09-20', '00:00', '04:00');
        $evening = $this->shift('target', '2026-09-20', '16:00', '22:00');

        $result = ShiftCompatibility::assess([$evening], [$morning], true, $this->now());

        self::assertTrue($result->compatible);
        self::assertNull($result->obstacle);
        self::assertSame('', $result->explanation);
    }

    public function test_overnight_work_blocks_the_following_morning(): void
    {
        $night = $this->shift('own', '2026-09-19', '22:00', '08:00', true, 'Custom noche');
        $morning = $this->shift('target', '2026-09-20', '07:00', '15:00');

        $result = ShiftCompatibility::assess([$morning], [$night], true, $this->now());

        self::assertSame(ShiftObstacle::SHIFT_OVERLAP, $result->obstacle);
    }

    public function test_short_rest_is_an_explained_advisory_and_does_not_block(): void
    {
        $custom = $this->shift('own', '2026-09-19', '20:00', '23:00', false, 'Guardia especial');
        $standard = $this->shift('target', '2026-09-20', '08:00', '16:00', false, 'Mañana');

        $result = ShiftCompatibility::assess([$standard], [$custom], true, $this->now());

        self::assertTrue($result->compatible);
        self::assertNull($result->obstacle);
        self::assertSame(
            'Terminarías a las 23:00, tendrías 9 h libres y volverías a trabajar a las 08:00. Puedes elegirlo igualmente.',
            $result->explanation,
        );
    }

    public function test_professional_group_is_a_hard_boundary(): void
    {
        $result = ShiftCompatibility::assess([$this->shift('target', '2026-09-20', '08:00', '16:00')], [], false, $this->now());

        self::assertFalse($result->compatible);
        self::assertSame(ShiftObstacle::NOT_IN_GROUP, $result->obstacle);
    }

    private function shift(string $id, string $date, string $start, string $end, bool $overnight = false, string $label = 'Turno'): RosteredShift
    {
        $endDate = $overnight ? (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') : $date;

        return new RosteredShift(
            'assignment-'.$id,
            'roster-'.$id,
            $date,
            new DateTimeImmutable($date.'T'.$start.':00+02:00'),
            new DateTimeImmutable($endDate.'T'.$end.':00+02:00'),
            $label,
            'T',
            $start,
            $end,
            $overnight,
            'slate',
            ShiftKind::OTHER,
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-13T10:00:00+02:00');
    }
}
