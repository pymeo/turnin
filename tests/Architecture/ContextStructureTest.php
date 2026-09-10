<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Conventions a reviewer would otherwise have to police by hand.
 *
 * Deptrac guards *dependencies* between layers; this guards the *shape* of the
 * tree and the vocabulary, which is what keeps a DDD codebase from decaying
 * into src/Service and src/Util two years in.
 */
final class ContextStructureTest extends TestCase
{
    private const LAYERS = ['Domain', 'Application', 'Infrastructure'];

    /**
     * Names that always turn out to mean "I did not know where to put this".
     * See docs/ARCHITECTURE.md § Naming.
     */
    private const BANNED_SUFFIXES = ['Manager', 'Helper', 'Util', 'Utils', 'Singleton', 'Data', 'Info'];

    #[DataProvider('sourceFiles')]
    public function test_every_class_lives_in_a_context_and_a_layer(string $relativePath): void
    {
        $segments = explode('/', $relativePath);

        self::assertGreaterThanOrEqual(
            3,
            \count($segments),
            \sprintf('%s is not inside <Context>/<Layer>/ or <Product>/<Context>/<Layer>/.', $relativePath),
        );

        $layer = $segments[1] ?? null;
        if (!\in_array($layer, self::LAYERS, true)) {
            $layer = $segments[2] ?? null;
        }

        self::assertContains(
            $layer,
            self::LAYERS,
            \sprintf('%s must sit under one of %s.', $relativePath, implode(', ', self::LAYERS)),
        );
    }

    #[DataProvider('sourceFiles')]
    public function test_no_class_hides_behind_a_catch_all_name(string $relativePath): void
    {
        $className = basename($relativePath, '.php');

        foreach (self::BANNED_SUFFIXES as $suffix) {
            self::assertFalse(
                str_ends_with($className, $suffix),
                \sprintf('"%s" is a placeholder name: say what %s actually does.', $suffix, $className),
            );
        }
    }

    #[DataProvider('sourceFiles')]
    public function test_the_namespace_matches_the_directory(string $relativePath): void
    {
        $source = (string) file_get_contents(self::sourceDir().'/'.$relativePath);
        $expected = 'App\\'.str_replace('/', '\\', \dirname($relativePath));

        self::assertMatchesRegularExpression(
            '/^namespace '.preg_quote($expected, '/').';$/m',
            $source,
            \sprintf('%s should declare namespace %s.', $relativePath, $expected),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sourceFiles(): iterable
    {
        foreach (self::phpFiles() as $relativePath) {
            yield $relativePath => [$relativePath];
        }
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(): array
    {
        $root = self::sourceDir();
        $files = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $relativePath = substr($file->getPathname(), \strlen($root) + 1);

            // The kernel is the framework's entry point, not application code.
            if ('Kernel.php' === $relativePath) {
                continue;
            }

            $files[] = str_replace('\\', '/', $relativePath);
        }

        sort($files);

        return $files;
    }

    private static function sourceDir(): string
    {
        return \dirname(__DIR__, 2).'/src';
    }
}
