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
}
