<?php

declare(strict_types=1);

namespace App\Platform\Identity\Domain;

use InvalidArgumentException;

final readonly class SpanishIdentityDocument
{
    private const CONTROL = 'TRWAGMYFPDXBNJZSQVHLCKE';

    public string $normalized;

    public function __construct(string $value)
    {
        $normalized = strtoupper((string) preg_replace('/[\s-]+/u', '', trim($value)));
        if (!preg_match('/^(?<number>\d{8}|[XYZ]\d{7})(?<letter>[A-Z])$/', $normalized, $matches)) {
            throw new InvalidArgumentException('Introduce un DNI o NIE válido.');
        }

        $number = $matches['number'];
        if (preg_match('/^[XYZ]/', $number)) {
            $number = strtr($number, ['X' => '0', 'Y' => '1', 'Z' => '2']);
        }
        if (self::CONTROL[((int) $number) % 23] !== $matches['letter']) {
            throw new InvalidArgumentException('La letra de control del DNI o NIE no es correcta.');
        }

        $this->normalized = $normalized;
    }
}
