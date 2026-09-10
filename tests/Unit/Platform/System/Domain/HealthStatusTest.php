<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform\System\Domain;

use App\Platform\System\Domain\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HealthStatus::class)]
final class HealthStatusTest extends TestCase
{
    public function test_the_worse_status_wins(): void
    {
        self::assertSame(HealthStatus::Unhealthy, HealthStatus::Healthy->worseOf(HealthStatus::Unhealthy));
        self::assertSame(HealthStatus::Unhealthy, HealthStatus::Unhealthy->worseOf(HealthStatus::Degraded));
        self::assertSame(HealthStatus::Degraded, HealthStatus::Degraded->worseOf(HealthStatus::Healthy));
    }

    public function test_comparing_a_status_with_itself_changes_nothing(): void
    {
        foreach (HealthStatus::cases() as $status) {
            self::assertSame($status, $status->worseOf($status));
        }
    }

    #[DataProvider('serviceability')]
    public function test_only_an_unhealthy_system_stops_serving(HealthStatus $status, bool $expected): void
    {
        self::assertSame($expected, $status->isServiceable());
    }

    /**
     * @return iterable<string, array{HealthStatus, bool}>
     */
    public static function serviceability(): iterable
    {
        yield 'healthy' => [HealthStatus::Healthy, true];
        // Losing the cache slows Turnin down; it does not make shift data wrong.
        yield 'degraded' => [HealthStatus::Degraded, true];
        yield 'unhealthy' => [HealthStatus::Unhealthy, false];
    }
}
