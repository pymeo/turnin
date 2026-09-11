<?php

declare(strict_types=1);

namespace App\Workforce\Domain;

/**
 * Which clock a centre runs on.
 *
 * Spain has two. Writing Europe/Madrid into the model would work for everyone
 * except the roughly two million people in the Canary Islands, whose shifts
 * would be shown an hour out — and whose night shift would land on the wrong
 * calendar day either side of midnight.
 *
 * The catalogue is the Ministry's, so the community name arrives in whatever
 * case and accents that export happens to use: "CANARIAS", "Canarias".
 */
final readonly class WorkplaceTimeZone
{
    public const PENINSULAR = 'Europe/Madrid';
    public const CANARY = 'Atlantic/Canary';

    public static function forAutonomousCommunity(?string $community): string
    {
        $normalized = self::normalize((string) $community);

        return str_contains($normalized, 'canaria') ? self::CANARY : self::PENINSULAR;
    }

    private static function normalize(string $value): string
    {
        return strtr(mb_strtolower(trim($value)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}
