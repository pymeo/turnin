<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\CalendarBlock;
use App\Scheduling\Domain\CalendarBlockSource;
use App\Scheduling\Domain\CalendarBlockType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CalendarBlockTest extends TestCase
{
    public function test_a_personal_block_is_not_a_work_shift_and_blocks_availability_by_choice(): void
    {
        $block = CalendarBlock::create('block-a', 'worker-a', 'Dentista', CalendarBlockType::APPOINTMENT, new DateTimeImmutable('2026-09-14T17:00:00+02:00'), new DateTimeImmutable('2026-09-14T18:00:00+02:00'), false, true, CalendarBlockSource::MANUAL, new DateTimeImmutable('2026-09-01'));

        self::assertSame('Dentista', $block->title());
        self::assertTrue($block->blocksAvailability());
        self::assertSame(CalendarBlockSource::MANUAL, $block->source());
    }

    public function test_google_identity_is_required_for_an_external_block(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalendarBlock::create('block-a', 'worker-a', 'Vacaciones', CalendarBlockType::VACATION, new DateTimeImmutable('2026-09-14'), new DateTimeImmutable('2026-09-15'), true, true, CalendarBlockSource::GOOGLE_CALENDAR, new DateTimeImmutable('2026-09-01'));
    }

    public function test_reimport_updates_the_same_external_block_only_when_google_is_newer(): void
    {
        $block = CalendarBlock::create('block-a', 'worker-a', 'Dentista', CalendarBlockType::APPOINTMENT, new DateTimeImmutable('2026-09-14T17:00:00+02:00'), new DateTimeImmutable('2026-09-14T18:00:00+02:00'), false, true, CalendarBlockSource::GOOGLE_CALENDAR, new DateTimeImmutable('2026-09-01'), 'calendar-a', 'event-a', new DateTimeImmutable('2026-09-01'));
        $block->synchronize('Dentista', new DateTimeImmutable('2026-09-14T18:00:00+02:00'), new DateTimeImmutable('2026-09-14T19:00:00+02:00'), false, new DateTimeImmutable('2026-09-02'), new DateTimeImmutable('2026-09-02'));
        $block->synchronize('Dato antiguo', new DateTimeImmutable('2026-09-14T10:00:00+02:00'), new DateTimeImmutable('2026-09-14T11:00:00+02:00'), false, new DateTimeImmutable('2026-08-31'), new DateTimeImmutable('2026-09-03'));

        self::assertSame('2026-09-14T18:00:00+02:00', $block->startsAt()->format(DateTimeImmutable::ATOM));
        self::assertSame('Dentista', $block->title());
    }
}
