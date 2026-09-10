<?php

declare(strict_types=1);

namespace App\Tests\Integration\Platform\System;

use App\Platform\System\Domain\HealthStatus;
use App\Platform\System\Infrastructure\Health\RedisProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedisProbe::class)]
final class RedisProbeTest extends TestCase
{
    public function test_it_reports_a_reachable_redis_as_healthy(): void
    {
        $component = (new RedisProbe(self::redisUrl()))->check();

        self::assertSame('cache', $component->name->value);
        self::assertSame(HealthStatus::Healthy, $component->status());
    }

    /**
     * The regression this locks down: probing through the PSR-6 pool reported a
     * healthy cache while Redis was down, because Symfony's adapters are built to
     * degrade silently on a dead backend.
     */
    public function test_losing_redis_only_degrades_the_system(): void
    {
        // Port 1 refuses immediately, so the probe fails fast instead of hanging.
        $component = (new RedisProbe('redis://127.0.0.1:1'))->check();

        self::assertSame(HealthStatus::Degraded, $component->status());
        self::assertSame('unreachable', $component->detail);
    }

    private static function redisUrl(): string
    {
        $url = $_ENV['REDIS_URL'] ?? $_SERVER['REDIS_URL'] ?? null;
        self::assertIsString($url, 'REDIS_URL must be defined in .env.test.');

        return $url;
    }
}
