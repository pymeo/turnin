<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swap\Domain;

use App\Swap\Domain\ShiftBalance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShiftBalanceTest extends TestCase
{
    /** @return iterable<string, array{int, int, int}> */
    public static function exchanges(): iterable
    {
        yield 'takes 12 h and gives 8 h' => [720, 480, 240];
        yield 'takes 8 h and gives 12 h' => [480, 720, -240];
        yield 'twelve for twelve' => [720, 720, 0];
    }

    #[DataProvider('exchanges')]
    public function test_balance_uses_real_duration(int $takes, int $gives, int $expected): void
    {
        $balance = ShiftBalance::forProposer($takes, $gives);

        self::assertSame($expected, $balance->minutes);
        self::assertSame(-$expected, $balance->forRequestOwner()->minutes);
    }
}
