<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Domain;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Domain\ExchangeBalance;
use App\Swap\Domain\ExchangeBalanceStatus;
use App\Swap\Domain\ReturnPreference;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExchangeBalanceTest extends TestCase
{
    public function test_twelve_hours_can_be_fully_consumed(): void
    {
        $balance = $this->balance();
        $balance->reserve(720, $this->now());
        $balance->redeemReserved(720, $this->now());

        self::assertSame(0, $balance->remainingMinutes());
        self::assertSame(ExchangeBalanceStatus::FULFILLED, $balance->status());
    }

    public function test_eight_hours_leave_four_pending(): void
    {
        $balance = $this->balance();
        $balance->reserve(480, $this->now());
        $balance->redeemReserved(480, $this->now());

        self::assertSame(240, $balance->remainingMinutes());
        self::assertSame(ExchangeBalanceStatus::PARTIALLY_REDEEMED, $balance->status());
    }

    public function test_two_open_proposals_cannot_reserve_the_same_minutes(): void
    {
        $balance = $this->balance();
        $balance->reserve(480, $this->now());

        $this->expectException(InvalidArgumentException::class);
        $balance->reserve(480, $this->now());
    }

    public function test_preference_is_optional_and_never_a_fictitious_shift(): void
    {
        $preference = new ReturnPreference('2026-10', ShiftKind::NIGHT, 720, [5, 6]);
        $balance = ExchangeBalance::earn('balance', 'ana', 'david', 'request', 'real-shift', 720, $preference, $this->now());

        self::assertSame('real-shift', $balance->sourceRosterDayId());
        self::assertSame('2026-10', $balance->preference()?->month);
        self::assertNull($this->balance()->preference());
    }

    private function balance(): ExchangeBalance
    {
        return ExchangeBalance::earn('balance', 'ana', 'david', 'request', 'real-shift', 720, null, $this->now());
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-20T20:30:00+02:00');
    }
}
