<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * The two or three numbers worth putting under a month grid.
 *
 * Every count here deliberately ignores days with no information. A month where
 * the worker filled in one week is not a month with 23 days off, and a summary
 * that says otherwise is worse than no summary: it is the number somebody will
 * quote at a supervisor.
 */
final readonly class RosterMonthSummary
{
    private function __construct(
        public int $workedDays,
        public int $restDays,
        public int $nightShifts,
        public int $unknownDays,
        public int $longestRestStreak,
        public ?WorkDate $longestRestStreakStart,
    ) {
    }

    /** @param list<RosterDay> $days */
    public static function of(RosterMonth $month, array $days): self
    {
        $byDate = [];
        foreach ($days as $day) {
            if ($month->contains($day->date())) {
                $byDate[(string) $day->date()] = $day;
            }
        }

        $worked = 0;
        $rest = 0;
        $nights = 0;
        $streak = 0;
        $bestStreak = 0;
        $streakStart = null;
        $bestStart = null;

        for ($number = 1; $number <= $month->length(); ++$number) {
            $date = WorkDate::of($month->year, $month->month, $number);
            $day = $byDate[(string) $date] ?? null;

            if (null !== $day && $day->isRest()) {
                ++$rest;
                $streakStart ??= $date;
                ++$streak;
                if ($streak > $bestStreak) {
                    $bestStreak = $streak;
                    $bestStart = $streakStart;
                }
                continue;
            }

            $streak = 0;
            $streakStart = null;

            if (null === $day) {
                continue;
            }
            ++$worked;
            if ($day->coversNightHours()) {
                ++$nights;
            }
        }

        return new self($worked, $rest, $nights, $month->length() - $worked - $rest, $bestStreak, $bestStart);
    }

    public function isEmpty(): bool
    {
        return 0 === $this->workedDays && 0 === $this->restDays;
    }
}
