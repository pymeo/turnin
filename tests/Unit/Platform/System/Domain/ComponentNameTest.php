<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform\System\Domain;

use App\Platform\System\Domain\ComponentName;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComponentName::class)]
final class ComponentNameTest extends TestCase
{
    public function test_it_keeps_the_name_it_was_given(): void
    {
        $name = new ComponentName('database');

        self::assertSame('database', $name->value);
        self::assertSame('database', (string) $name);
    }

    public function test_two_names_with_the_same_value_are_equal(): void
    {
        self::assertTrue((new ComponentName('cache'))->equals(new ComponentName('cache')));
        self::assertFalse((new ComponentName('cache'))->equals(new ComponentName('schema')));
    }

    #[DataProvider('invalidNames')]
    public function test_it_rejects_names_that_would_break_the_operational_contract(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ComponentName($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'single character' => ['a'];
        yield 'uppercase' => ['Database'];
        yield 'starts with a digit' => ['1database'];
        yield 'contains a dash' => ['read-replica'];
        yield 'contains a space' => ['read replica'];
        yield 'too long' => [str_repeat('a', 33)];
    }
}
