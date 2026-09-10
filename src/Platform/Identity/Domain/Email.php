<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

use InvalidArgumentException;

final readonly class Email
{
    public string $value;

    public function __construct(string $value)
    {
        $value = mb_strtolower(trim($value));
        if (!filter_var($value, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Introduce un correo válido.');
        } $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
