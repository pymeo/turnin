<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling\Domain;

use App\Scheduling\Domain\DateExpressionParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DateExpressionParserTest extends TestCase
{
    #[DataProvider('expressions')]
    public function test_it_expands_day_expressions(string $expression, string $expected): void
    {
        self::assertSame($expected, implode(',', (new DateExpressionParser())->expand($expression)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function expressions(): iterable
    {
        yield 'a single day' => ['1', '1'];
        yield 'two joined by y' => ['1 y 2', '1,2'];
        yield 'a comma list' => ['1, 2 y 3', '1,2,3'];
        yield 'del … al' => ['del 1 al 4', '1,2,3,4'];
        yield 'desde … hasta' => ['desde 10 hasta 12', '10,11,12'];
        yield 'a dash' => ['1-4', '1,2,3,4'];
        yield 'an en dash, as phone keyboards produce' => ['1–4', '1,2,3,4'];
        yield 'spelled numbers' => ['uno y dos', '1,2'];
        yield 'mixed spelling and digits' => ['del uno al 3', '1,2,3'];
        yield 'the twenties have one word each' => ['veintiocho', '28'];
        yield 'thirty-one is one number' => ['treinta y uno', '31'];
        yield 'repeats are collapsed' => ['1, 1 y 2', '1,2'];
        yield 'days beyond a month are dropped' => ['40', ''];
        yield 'no numbers at all' => ['lo que sea', ''];
    }
}
