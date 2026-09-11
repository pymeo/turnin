<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * Turns the day part of a dictated clause into day numbers: "1 y 2",
 * "1, 2 y 3", "del 1 al 4", "1-4".
 *
 * Spelled-out numbers matter more than they look. Android and iOS dictation
 * transcribe "uno y dos" as words about as often as digits, and a parser that
 * only reads digits fails on half of what a phone actually produces.
 */
final readonly class DateExpressionParser
{
    private const UNITS = [
        'cero' => 0, 'uno' => 1, 'una' => 1, 'un' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4,
        'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10,
        'once' => 11, 'doce' => 12, 'trece' => 13, 'catorce' => 14, 'quince' => 15,
        'dieciseis' => 16, 'diecisiete' => 17, 'dieciocho' => 18, 'diecinueve' => 19,
        'veinte' => 20, 'veintiuno' => 21, 'veintiuna' => 21, 'veintidos' => 22, 'veintitres' => 23,
        'veinticuatro' => 24, 'veinticinco' => 25, 'veintiseis' => 26, 'veintisiete' => 27,
        'veintiocho' => 28, 'veintinueve' => 29, 'treinta' => 30,
    ];

    private const RANGE_OPENERS = ['del', 'desde', 'de'];
    private const RANGE_CLOSERS = ['al', 'hasta', 'a', '-'];

    /**
     * @return list<int> Day-of-month numbers, in the order given, without repeats
     */
    public function expand(string $expression): array
    {
        $tokens = $this->tokenize($expression);
        $days = [];
        $awaitingRangeEnd = false;

        foreach ($tokens as $token) {
            if (\in_array($token, self::RANGE_CLOSERS, true)) {
                $awaitingRangeEnd = [] !== $days;
                continue;
            }
            if (\in_array($token, [...self::RANGE_OPENERS, 'y', 'e', ','], true)) {
                continue;
            }

            $number = $this->toNumber($token);
            if (null === $number) {
                continue;
            }

            if ($awaitingRangeEnd) {
                $awaitingRangeEnd = false;
                $start = $days[\count($days) - 1];
                for ($day = $start + 1; $day <= $number; ++$day) {
                    $days[] = $day;
                }
                continue;
            }

            $days[] = $number;
        }

        return array_values(array_unique(array_filter($days, static fn (int $day): bool => $day >= 1 && $day <= 31)));
    }

    public function toNumber(string $token): ?int
    {
        if (1 === preg_match('/^\d{1,2}$/D', $token)) {
            return (int) $token;
        }

        return self::UNITS[$token] ?? null;
    }

    /** @return list<string> */
    private function tokenize(string $expression): array
    {
        $normalized = SpokenTerm::normalize($expression);
        // The only two-word number a day of the month can be.
        $normalized = str_replace(['treinta y uno', 'treinta y una'], '31', $normalized);
        // "1-4" and "1 al 4" mean the same thing, so the dash becomes a token.
        $spaced = (string) preg_replace('/[-–—]/u', ' - ', $normalized);
        preg_match_all('/\d{1,2}|[a-z]+|[,\-]/u', $spaced, $matches);

        /** @var list<string> $tokens */
        $tokens = $matches[0];

        return $tokens;
    }
}
