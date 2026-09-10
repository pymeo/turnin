<?php

declare(strict_types=1);

namespace App\Platform\Identity\Infrastructure\Security;

use App\Platform\Identity\Domain\PasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final readonly class SymfonyPasswordHasher implements PasswordHasher
{
    public function __construct(private PasswordHasherFactoryInterface $factory)
    {
    }

    public function hash(string $plainText): string
    {
        return $this->factory->getPasswordHasher(SecurityUser::class)->hash($plainText);
    }

    public function verify(string $hash, string $plainText): bool
    {
        return $this->factory->getPasswordHasher(SecurityUser::class)->verify($hash, $plainText);
    }
}
