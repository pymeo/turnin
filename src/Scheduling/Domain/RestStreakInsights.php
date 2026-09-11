<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * Reads runs of confirmed rest days out of a month. No engine, no guesswork:
 * if it is on screen, it is in the calendar.
 */
final readonly class RestStreakInsights implements CalendarInsightSource
{
    private const MINIMUM_INTERESTING_STREAK = 3;

    private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function insightsFor(RosterMonth $month, array $days): array
    {
        $summary = RosterMonthSummary::of($month, $days);
        if ($summary->longestRestStreak < self::MINIMUM_INTERESTING_STREAK || null === $summary->longestRestStreakStart) {
            return [];
        }

        $start = $summary->longestRestStreakStart;
        $end = $start->plusDays($summary->longestRestStreak - 1);

        return [new CalendarInsight(
            \sprintf('Tienes %d días libres seguidos', $summary->longestRestStreak),
            \sprintf('Del %d al %d de %s.', $start->day, $end->day, self::MONTHS[$month->month - 1]),
        )];
    }
}
