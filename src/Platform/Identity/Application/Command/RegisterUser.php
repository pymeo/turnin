<?php

declare(strict_types=1);

namespace App\Platform\Identity\Application\Command;

final readonly class RegisterUser
{
    public function __construct(public string $email, public string $password)
    {
    }
}
