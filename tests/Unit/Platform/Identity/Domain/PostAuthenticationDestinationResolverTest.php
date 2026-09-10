<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform\Identity\Domain;

use App\Platform\Identity\Domain\PostAuthenticationDestinationResolver;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class PostAuthenticationDestinationResolverTest extends TestCase
{
    #[TestWith([false, false, '/onboarding'])]
    #[TestWith([true, false, '/app'])]
    #[TestWith([true, true, '/app'])]
    #[TestWith([false, true, '/supervisor'])]
    public function test_it_chooses_the_primary_authenticated_context(bool $worker, bool $supervisor, string $expected): void
    {
        self::assertSame($expected, (new PostAuthenticationDestinationResolver())->resolve($worker, $supervisor));
    }

    public function test_it_prefers_a_safe_internal_target_path(): void
    {
        self::assertSame('/approval/abc12345', (new PostAuthenticationDestinationResolver())->resolve(false, false, '/approval/abc12345'));
    }

    public function test_it_rejects_external_and_protocol_relative_targets(): void
    {
        $resolver = new PostAuthenticationDestinationResolver();

        self::assertSame('/onboarding', $resolver->resolve(false, false, 'https://evil.example/steal'));
        self::assertSame('/onboarding', $resolver->resolve(false, false, '//evil.example/steal'));
    }
}
