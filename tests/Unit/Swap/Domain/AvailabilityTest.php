<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Domain;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Domain\Availability;
use App\Swap\Domain\WorkDate;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AvailabilityTest extends TestCase
{
    private const TODAY = '2026-09-15';

    public function test_declaring_makes_it_active(): void
    {
        $availability = $this->declare('2026-09-21');

        self::assertTrue($availability->isActive());
        self::assertSame('pool-uci', $availability->swapPoolId());
    }

    public function test_a_past_day_cannot_be_offered(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('días futuros');

        $this->declare('2026-09-14');
    }

    /** Two taps on a phone are one statement. */
    public function test_reaffirming_an_active_declaration_changes_nothing(): void
    {
        $availability = $this->declare('2026-09-21');
        $before = $availability->updatedAt();

        $availability->reaffirm(new DateTimeImmutable('2026-09-16T10:00:00+00:00'));

        self::assertTrue($availability->isActive());
        self::assertSame($before, $availability->updatedAt());
    }

    public function test_withdrawing_and_declaring_again_reuses_the_same_statement(): void
    {
        $availability = $this->declare('2026-09-21');

        $availability->withdraw('maria', $this->now());
        self::assertFalse($availability->isActive());

        $availability->reaffirm(new DateTimeImmutable('2026-09-16T10:00:00+00:00'));
        self::assertTrue($availability->isActive());
    }

    public function test_only_its_owner_can_withdraw_it(): void
    {
        $availability = $this->declare('2026-09-21');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tu propia disponibilidad');
        $availability->withdraw('pedro', $this->now());
    }

    public function test_withdrawing_twice_is_withdrawn(): void
    {
        $availability = $this->declare('2026-09-21');
        $availability->withdraw('maria', $this->now());
        $availability->withdraw('maria', $this->now());

        self::assertFalse($availability->isActive());
    }

    private function declare(string $date): Availability
    {
        return Availability::declare(
            'availability-1',
            'maria',
            'assignment-2',
            'pool-uci',
            WorkDate::fromString($date),
            ShiftKind::MORNING,
            WorkDate::fromString(self::TODAY),
            $this->now(),
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-15T10:00:00+00:00');
    }
}
