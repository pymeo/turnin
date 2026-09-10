<?php

declare(strict_types=1);

namespace App\Tests\Integration\Platform\System;

use App\Platform\System\Domain\HealthStatus;
use App\Platform\System\Infrastructure\Health\DatabaseProbe;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DatabaseProbe::class)]
final class DatabaseProbeTest extends KernelTestCase
{
    public function test_it_reports_the_real_database_as_healthy(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $component = (new DatabaseProbe($connection))->check();

        self::assertSame('database', $component->name->value);
        self::assertSame(HealthStatus::Healthy, $component->status());
    }

    public function test_an_unreachable_database_is_a_result_not_an_exception(): void
    {
        // Port 1 refuses immediately, so the probe fails fast instead of hanging.
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => '127.0.0.1',
            'port' => 1,
            'dbname' => 'nowhere',
            'user' => 'nobody',
            'password' => 'nothing',
        ]);

        $component = (new DatabaseProbe($connection))->check();

        self::assertSame(HealthStatus::Unhealthy, $component->status());
        // The DSN must never leak through /health.
        self::assertSame('unreachable', $component->detail);
    }
}
