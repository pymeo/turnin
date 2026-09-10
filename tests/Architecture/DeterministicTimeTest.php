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
 * Turnin is a product about time. Any rule that reads the wall clock directly is
 * a rule that cannot be tested — "does this shift swap leave a legal rest gap"
 * has to be answerable for an arbitrary instant, not just for right now.
 *
 * So Domain and Application take the current time through a clock port
 * (Psr\Clock\ClockInterface) and never reach for it themselves. Infrastructure
 * is where the real clock is allowed to exist.
 *
 * See docs/DOMAIN.md § Time and docs/adr/0005-time-and-clock.md.
 */
final class DeterministicTimeTest extends TestCase
{
    private const FORBIDDEN = [
        '/\bnew\s+\\\\?DateTimeImmutable\s*\(\s*(?![\'"]@)/i' => 'construct DateTimeImmutable from the current time',
        '/\bnew\s+\\\\?DateTime\s*\(\s*(?![\'"]@)/i' => 'construct DateTime from the current time',
        '/(?<![\w\\\\$>])time\s*\(\s*\)/i' => 'call time()',
        '/(?<![\w\\\\$>])microtime\s*\(/i' => 'call microtime()',
        '/(?<![\w\\\\$>])date\s*\(/i' => 'call date()',
        '/(?<![\w\\\\$>])strtotime\s*\(/i' => 'call strtotime()',
        '/(?<![\w\\\\$>])mktime\s*\(/i' => 'call mktime()',
    ];

    #[DataProvider('domainAndApplicationFiles')]
    public function test_business_logic_takes_time_from_the_clock_port(string $relativePath): void
    {
        $source = self::withoutCommentsAndStrings((string) file_get_contents(self::sourceDir().'/'.$relativePath));

        foreach (self::FORBIDDEN as $pattern => $what) {
            self::assertSame(
                0,
                preg_match($pattern, $source),
                \sprintf(
                    '%s must not %s. Inject Psr\Clock\ClockInterface instead so the behaviour can be tested at any instant.',
                    $relativePath,
                    $what,
                ),
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function domainAndApplicationFiles(): iterable
    {
        $root = self::sourceDir();

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        $files = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));

            if (1 === preg_match('#^[^/]+/[^/]+/(Domain|Application)/#', $relativePath)) {
                $files[] = $relativePath;
            }
        }

        sort($files);

        self::assertNotEmpty($files, 'No domain or application code found — has the layout changed?');

        foreach ($files as $relativePath) {
            yield $relativePath => [$relativePath];
        }
    }

    /**
     * Doc blocks legitimately mention `new DateTimeImmutable()` while explaining
     * why it is banned, and a string literal is not a call.
     */
    private static function withoutCommentsAndStrings(string $source): string
    {
        $stripped = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                if (\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT, \T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                $stripped .= $token[1];
                continue;
            }

            $stripped .= $token;
        }

        return $stripped;
    }

    private static function sourceDir(): string
    {
        return \dirname(__DIR__, 2).'/src';
    }
}
