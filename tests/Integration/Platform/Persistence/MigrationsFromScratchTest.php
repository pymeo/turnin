<?php

declare(strict_types=1);

namespace App\Tests\Integration\Platform\Persistence;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The guarantee: an empty PostgreSQL database can reach the current schema using
 * nothing but `doctrine:migrations:migrate`.
 *
 * This is what stops us discovering in six months that a migration written today
 * no longer replays — by then the only people who could fix it have forgotten
 * why it existed. It runs the real console command against a throwaway database,
 * so it exercises exactly what CI and production run, not a reimplementation.
 */
#[Group('migrations')]
final class MigrationsFromScratchTest extends TestCase
{
    private const MIGRATION_FILE_PATTERN = '/^Version\d{14}\.php$/';

    private string $databaseName;

    protected function setUp(): void
    {
        $this->databaseName = 'turnin_scratch_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->console(['doctrine:database:drop', '--force', '--if-exists']);
    }

    public function test_an_empty_database_reaches_the_current_schema_through_migrations_alone(): void
    {
        $create = $this->console(['doctrine:database:create']);
        self::assertSame(0, $create->getExitCode(), $create->getErrorOutput());

        // Guard rail: if the override below ever stops reaching the child, this
        // test would silently drop the shared test database in tearDown instead
        // of failing. Prove it worked on a throwaway one.
        self::assertStringContainsString($this->databaseName, $create->getOutput());

        $migrate = $this->console(['doctrine:migrations:migrate', '--allow-no-migration']);
        self::assertSame(0, $migrate->getExitCode(), "Replaying every migration failed:\n".$migrate->getErrorOutput().$migrate->getOutput());

        $upToDate = $this->console(['doctrine:migrations:up-to-date']);
        self::assertSame(0, $upToDate->getExitCode(), "The database is not at the latest version after migrating:\n".$upToDate->getOutput());
    }

    public function test_every_migration_on_disk_is_recorded_as_executed(): void
    {
        $this->console(['doctrine:database:create']);
        $this->console(['doctrine:migrations:migrate', '--allow-no-migration']);

        $status = $this->console(['doctrine:migrations:list']);
        self::assertSame(0, $status->getExitCode(), $status->getErrorOutput());

        foreach ($this->migrationVersionsOnDisk() as $version) {
            self::assertStringContainsString(
                $version,
                $status->getOutput(),
                \sprintf('Migration %s exists on disk but Doctrine never executed it.', $version),
            );
        }
    }

    public function test_orphaned_workforce_destinations_are_quarantined_before_constraints_are_added(): void
    {
        $this->console(['doctrine:database:create']);
        $beforeRepair = $this->console(['doctrine:migrations:migrate', 'DoctrineMigrations\\Version20260912120000']);
        self::assertSame(0, $beforeRepair->getExitCode(), $beforeRepair->getErrorOutput().$beforeRepair->getOutput());

        $database = parse_url($this->throwawayDatabaseUrl());
        self::assertIsArray($database);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $database['host'] ?? '127.0.0.1',
            'port' => $database['port'] ?? 5432,
            'dbname' => ltrim($database['path'] ?? '', '/'),
            'user' => $database['user'] ?? '',
            'password' => $database['pass'] ?? '',
        ]);
        $assignmentId = '0199ffff-0000-7000-8000-000000000001';
        $poolId = '0199ffff-0000-7000-8000-000000000002';
        $missingUnitId = '0199ffff-0000-7000-8000-000000000003';
        $now = '2026-09-12T14:00:00+00:00';
        $connection->insert('workforce_worker_assignments', [
            'id' => $assignmentId,
            'worker_id' => '0199ffff-0000-7000-8000-000000000004',
            'workplace_id' => '0199ffff-0000-7000-8000-000000000005',
            'staff_category_id' => '0199ffff-0000-7000-8000-000000000006',
            'organizational_unit_id' => $missingUnitId,
            'primary_assignment' => true,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->insert('workforce_swap_pools', [
            'id' => $poolId,
            'workplace_id' => '0199ffff-0000-7000-8000-000000000005',
            'staff_category_id' => '0199ffff-0000-7000-8000-000000000006',
            'organizational_unit_id' => $missingUnitId,
            'fingerprint' => 'orphaned-destination-fixture',
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connection->insert('workforce_swap_pool_memberships', [
            'id' => '0199ffff-0000-7000-8000-000000000007',
            'swap_pool_id' => $poolId,
            'worker_id' => '0199ffff-0000-7000-8000-000000000004',
            'assignment_id' => $assignmentId,
            'source' => 'self_declared',
            'is_primary' => true,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $repair = $this->console(['doctrine:migrations:migrate', '--allow-no-migration']);
        self::assertSame(0, $repair->getExitCode(), $repair->getErrorOutput().$repair->getOutput());
        self::assertFalse((bool) $connection->fetchOne('SELECT active FROM workforce_worker_assignments WHERE id = ?', [$assignmentId]));
        self::assertNull($connection->fetchOne('SELECT organizational_unit_id FROM workforce_worker_assignments WHERE id = ?', [$assignmentId]));
        self::assertFalse((bool) $connection->fetchOne('SELECT active FROM workforce_swap_pools WHERE id = ?', [$poolId]));
        self::assertNull($connection->fetchOne('SELECT organizational_unit_id FROM workforce_swap_pools WHERE id = ?', [$poolId]));
        self::assertFalse((bool) $connection->fetchOne('SELECT active FROM workforce_swap_pool_memberships WHERE swap_pool_id = ?', [$poolId]));
        $constraintCount = $connection->fetchOne("SELECT COUNT(*) FROM pg_constraint WHERE conname IN ('workforce_assignment_unit_fk', 'workforce_swap_pool_unit_fk')");
        self::assertIsNumeric($constraintCount);
        self::assertSame(2, (int) $constraintCount);
        $connection->close();
    }

    /**
     * @return list<string>
     */
    private function migrationVersionsOnDisk(): array
    {
        $directory = \dirname(__DIR__, 4).'/migrations';
        self::assertDirectoryExists($directory);

        $versions = [];
        foreach (scandir($directory) ?: [] as $file) {
            if (1 === preg_match(self::MIGRATION_FILE_PATTERN, $file)) {
                $versions[] = basename($file, '.php');
            }
        }

        return $versions;
    }

    /**
     * @param list<string> $command
     */
    private function console(array $command): Process
    {
        $projectDir = \dirname(__DIR__, 4);

        $process = new Process(
            ['php', 'bin/console', ...$command, '--no-interaction', '--env=test'],
            $projectDir,
            [
                // Point the whole console at a database nobody else is using.
                'DATABASE_URL' => $this->throwawayDatabaseUrl(),
                // Dotenv records the variables it owns in SYMFONY_DOTENV_VARS and
                // re-overrides them on the next boot. Inherited from this process,
                // it would make the child ignore the DATABASE_URL above and reach
                // for .env.test instead — i.e. the real test database.
                'SYMFONY_DOTENV_VARS' => false,
            ],
        );
        $process->run();

        return $process;
    }

    private function throwawayDatabaseUrl(): string
    {
        $url = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? null;
        self::assertIsString($url, 'DATABASE_URL must be defined in .env.test.');

        $parts = parse_url($url);
        self::assertIsArray($parts);

        $query = $parts['query'] ?? '';

        return \sprintf(
            '%s://%s:%s@%s:%s/%s%s',
            $parts['scheme'] ?? 'postgresql',
            $parts['user'] ?? '',
            $parts['pass'] ?? '',
            $parts['host'] ?? '127.0.0.1',
            $parts['port'] ?? 5432,
            $this->databaseName,
            '' !== $query ? '?'.$query : '',
        );
    }
}
