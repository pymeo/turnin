<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

use Stringable;

/**
 * A word as the worker said or typed it, reduced to something comparable:
 * lower case, unaccented, single-spaced. "Mañana", "MANANA" and " mañana " are
 * the same instruction and speech transcripts are inconsistent about all three.
 */
final readonly class SpokenTerm implements Stringable
{
    public string $value;

    public function __construct(string $raw)
    {
        $this->value = self::normalize($raw);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function isEmpty(): bool
    {
        return '' === $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public static function normalize(string $raw): string
    {
        $lowered = mb_strtolower(trim($raw));
        $unaccented = strtr($lowered, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', $unaccented));
    }
}
