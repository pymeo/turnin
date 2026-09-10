<?php

declare(strict_types=1);

namespace App\Tests\Integration\Platform\Persistence;

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
