<?php

declare(strict_types=1);

namespace App\Coordination\Domain;

use DateInterval;
use DateTimeImmutable;

final class ScheduleComparator
{
    private const SIMILAR_MINUTES = 30;

    /**
     * @param list<BusyInterval> $mine
     * @param list<BusyInterval> $theirs
     *
     * @return list<ScheduleDayComparison>
     */
    public function compare(DateTimeImmutable $from, DateTimeImmutable $to, array $mine, array $theirs): array
    {
        $days = [];
        for ($day = $from->setTime(0, 0); $day < $to; $day = $day->add(new DateInterval('P1D'))) {
            $end = $day->add(new DateInterval('P1D'));
            $a = $this->overlapping($mine, $day, $end);
            $b = $this->overlapping($theirs, $day, $end);
            [$status, $actionable] = $this->status($a, $b);
            $days[] = new ScheduleDayComparison($day->format('Y-m-d'), $status, $a, $b, $actionable);
        }

        usort($days, static fn (ScheduleDayComparison $left, ScheduleDayComparison $right): int => [self::priority($left->status), $left->date] <=> [self::priority($right->status), $right->date]);

        return $days;
    }

    /**
     * @param list<BusyInterval> $entries
     *
     * @return list<BusyInterval>
     */
    private function overlapping(array $entries, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return array_values(array_filter($entries, static fn (BusyInterval $entry): bool => $entry->startsAt < $end && $entry->endsAt > $start));
    }

    /**
     * @param list<BusyInterval> $a
     * @param list<BusyInterval> $b
     *
     * @return array{ComparisonStatus, ?BusyInterval}
     */
    private function status(array $a, array $b): array
    {
        if ([] === $a && [] === $b) {
            return [ComparisonStatus::BOTH_FREE, null];
        }
        $aWork = array_values(array_filter($a, static fn (BusyInterval $entry): bool => $entry->work));
        $bWork = array_values(array_filter($b, static fn (BusyInterval $entry): bool => $entry->work));
        if ([] !== $aWork && [] === $b) {
            return [ComparisonStatus::A_WORKS_B_FREE, $aWork[0]];
        }
        if ([] === $a && [] !== $bWork) {
            return [ComparisonStatus::A_FREE_B_WORKS, null];
        }
        if ([] !== $aWork && [] !== $bWork) {
            return [$this->similar($aWork[0], $bWork[0]) ? ComparisonStatus::BOTH_WORKING_SIMILAR_HOURS : ComparisonStatus::DIFFERENT_WORKING_HOURS, null];
        }

        return [ComparisonStatus::BOTH_BUSY_DIFFERENTLY, null];
    }

    private function similar(BusyInterval $a, BusyInterval $b): bool
    {
        return abs($a->startsAt->getTimestamp() - $b->startsAt->getTimestamp()) <= self::SIMILAR_MINUTES * 60
            && abs($a->endsAt->getTimestamp() - $b->endsAt->getTimestamp()) <= self::SIMILAR_MINUTES * 60;
    }

    private static function priority(ComparisonStatus $status): int
    {
        return match ($status) {
            ComparisonStatus::BOTH_FREE => 1,
            ComparisonStatus::A_WORKS_B_FREE, ComparisonStatus::A_FREE_B_WORKS => 2,
            ComparisonStatus::DIFFERENT_WORKING_HOURS => 3,
            default => 4,
        };
    }
}
