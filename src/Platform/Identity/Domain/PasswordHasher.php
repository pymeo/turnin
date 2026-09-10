<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

interface PasswordHasher
{
    public function hash(string $plainText): string;

    public function verify(string $hash, string $plainText): bool;
}
