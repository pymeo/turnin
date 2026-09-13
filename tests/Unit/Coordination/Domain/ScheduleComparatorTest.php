<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coordination\Domain;

use App\Coordination\Domain\BusyInterval;
use App\Coordination\Domain\ComparisonStatus;
use App\Coordination\Domain\ScheduleComparator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ScheduleComparatorTest extends TestCase
{
    public function test_it_prioritizes_days_free_together_then_one_shift_opportunities(): void
    {
        $from = new DateTimeImmutable('2026-09-18 00:00 Europe/Madrid');
        $to = new DateTimeImmutable('2026-09-22 00:00 Europe/Madrid');
        $mine = [
            new BusyInterval(new DateTimeImmutable('2026-09-19 22:00 Europe/Madrid'), new DateTimeImmutable('2026-09-20 08:00 Europe/Madrid'), true, 'Noche', 'assignment-a', '2026-09-19'),
            new BusyInterval(new DateTimeImmutable('2026-09-20 08:00 Europe/Madrid'), new DateTimeImmutable('2026-09-20 15:00 Europe/Madrid'), true, 'Mañana', 'assignment-a', '2026-09-20'),
        ];
        $theirs = [new BusyInterval(new DateTimeImmutable('2026-09-21 15:00 Europe/Madrid'), new DateTimeImmutable('2026-09-21 22:00 Europe/Madrid'), true, 'Tarde')];

        $days = (new ScheduleComparator())->compare($from, $to, $mine, $theirs);
        self::assertSame('2026-09-18', $days[0]->date);
        self::assertSame(ComparisonStatus::BOTH_FREE, $days[0]->status);
        self::assertSame(ComparisonStatus::A_WORKS_B_FREE, $days[1]->status);
        self::assertNotNull($days[1]->actionableShift);
        self::assertSame(ComparisonStatus::A_WORKS_B_FREE, $days[2]->status);
        self::assertSame(ComparisonStatus::A_FREE_B_WORKS, $days[3]->status);
    }

    public function test_private_block_means_busy_without_exposing_its_title(): void
    {
        $from = new DateTimeImmutable('2026-09-18 00:00 Europe/Madrid');
        $mine = [new BusyInterval(new DateTimeImmutable('2026-09-18 10:00 Europe/Madrid'), new DateTimeImmutable('2026-09-18 12:00 Europe/Madrid'), false)];

        $day = (new ScheduleComparator())->compare($from, $from->modify('+1 day'), $mine, [])[0];

        self::assertSame(ComparisonStatus::BOTH_BUSY_DIFFERENTLY, $day->status);
        self::assertSame('Ocupado', $day->mine[0]->label);
    }

    public function test_real_intervals_within_thirty_minutes_are_similar_even_with_different_labels(): void
    {
        $from = new DateTimeImmutable('2026-09-18 00:00 Europe/Madrid');
        $mine = [new BusyInterval(new DateTimeImmutable('2026-09-18 08:00 Europe/Madrid'), new DateTimeImmutable('2026-09-18 15:00 Europe/Madrid'), true, 'Mañana')];
        $theirs = [new BusyInterval(new DateTimeImmutable('2026-09-18 08:15 Europe/Madrid'), new DateTimeImmutable('2026-09-18 15:00 Europe/Madrid'), true, 'Hospital')];

        $day = (new ScheduleComparator())->compare($from, $from->modify('+1 day'), $mine, $theirs)[0];

        self::assertSame(ComparisonStatus::BOTH_WORKING_SIMILAR_HOURS, $day->status);
    }
}
