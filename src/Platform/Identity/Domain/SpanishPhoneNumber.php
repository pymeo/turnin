<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

use InvalidArgumentException;

final readonly class SpanishPhoneNumber
{
    public string $e164;

    public function __construct(string $value)
    {
        $compact = (string) preg_replace('/[\s().-]+/u', '', trim($value));
        if (str_starts_with($compact, '0034')) {
            $compact = '+34'.substr($compact, 4);
        } elseif (!str_starts_with($compact, '+34')) {
            $compact = '+34'.$compact;
        }
        if (!preg_match('/^\+34[6-9]\d{8}$/', $compact)) {
            throw new InvalidArgumentException('Introduce un teléfono español válido.');
        }

        $this->e164 = $compact;
    }
}
