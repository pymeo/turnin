<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workforce\Domain;

use App\Workforce\Domain\ImportedWorkplace;
use App\Workforce\Domain\Workplace;
use App\Workforce\Domain\WorkplaceId;
use App\Workforce\Domain\WorkplaceSource;
use App\Workforce\Domain\WorkplaceType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class WorkplaceTest extends TestCase
{
    public function test_it_only_changes_timestamps_when_the_official_record_changes(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01T10:00:00+00:00');
        $later = new DateTimeImmutable('2026-02-01T10:00:00+00:00');
        $imported = $this->imported('Hospital Uno', 'Granada');
        $workplace = Workplace::import(
            new WorkplaceId('019b76da-a800-7000-8000-000000000001'),
            $imported,
            $createdAt,
        );

        self::assertFalse($workplace->synchronize($imported, $later));
        self::assertSame($createdAt, $workplace->updatedAt());

        self::assertTrue($workplace->synchronize($this->imported('Hospital Dos', 'Armilla'), $later));
        self::assertSame('Hospital Dos', $workplace->name());
        self::assertSame('Armilla', $workplace->municipality());
        self::assertSame($later, $workplace->sourceUpdatedAt());
        self::assertSame($later, $workplace->updatedAt());
    }

    public function test_a_disappearing_workplace_is_inactivated_and_can_reappear(): void
    {
        $first = new DateTimeImmutable('2026-01-01T10:00:00+00:00');
        $second = new DateTimeImmutable('2026-02-01T10:00:00+00:00');
        $third = new DateTimeImmutable('2026-03-01T10:00:00+00:00');
        $imported = $this->imported('Hospital Uno', 'Granada');
        $workplace = Workplace::import(new WorkplaceId('019b76da-a800-7000-8000-000000000001'), $imported, $first);

        self::assertTrue($workplace->deactivate($second));
        self::assertFalse($workplace->active());
        self::assertSame($second, $workplace->sourceUpdatedAt());
        self::assertFalse($workplace->deactivate($third));

        self::assertTrue($workplace->synchronize($imported, $third));
        self::assertTrue($workplace->active());
        self::assertSame($third, $workplace->updatedAt());
    }

    private function imported(string $name, string $municipality): ImportedWorkplace
    {
        return new ImportedWorkplace(
            WorkplaceSource::MINISTRY_HOSPITALS,
            '0118000234',
            $name,
            WorkplaceType::HOSPITAL,
            'Andalucía',
            'Granada',
            $municipality,
        );
    }
}
