<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

use InvalidArgumentException;

final readonly class UserId
{
    public function __construct(public string $value)
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $value)) {
            throw new InvalidArgumentException('Invalid user id.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
