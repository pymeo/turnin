<?php

declare(strict_types=1);

namespace App\Tests\Integration\Platform\System;

use App\Platform\System\Domain\HealthStatus;
use App\Platform\System\Infrastructure\Health\SchemaVersionProbe;
use Doctrine\Migrations\DependencyFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(SchemaVersionProbe::class)]
final class SchemaVersionProbeTest extends KernelTestCase
{
    /**
     * The test database is built by `composer test-reset-db`, which runs the real
     * migrations. If this ever fails, the test schema and the migrations have
     * drifted apart — which is exactly what we want to hear about.
     */
    public function test_a_migrated_database_reports_a_healthy_schema(): void
    {
        self::bootKernel();
        $migrations = self::getContainer()->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $migrations);

        $component = (new SchemaVersionProbe($migrations))->check();

        self::assertSame('schema', $component->name->value);
        self::assertSame(HealthStatus::Healthy, $component->status(), 'The test database has pending migrations.');
    }
}
