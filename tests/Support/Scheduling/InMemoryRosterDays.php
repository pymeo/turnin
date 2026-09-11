<?php

declare(strict_types=1);

namespace App\Tests\Support\Scheduling;

use App\Scheduling\Domain\RosterDay;
use App\Scheduling\Domain\RosterDays;
use App\Scheduling\Domain\WorkDate;

/**
 * A roster that lives in an array. Enough to exercise the handlers without a
 * database — the SQL itself is covered in tests/Integration.
 */
final class InMemoryRosterDays implements RosterDays
{
    /** @var array<string, array<string, RosterDay>> */
    private array $days = [];

    public int $applyCalls = 0;

    /** @param list<RosterDay> $seed */
    public function __construct(array $seed = [])
    {
        $this->apply($seed[0]?->workerAssignmentId() ?? 'unused', $seed, []);
        $this->applyCalls = 0;
    }

    public function inRange(string $workerAssignmentId, WorkDate $from, WorkDate $to): array
    {
        $found = [];
        foreach ($this->days[$workerAssignmentId] ?? [] as $date => $day) {
            if ($date >= (string) $from && $date <= (string) $to) {
                $found[] = $day;
            }
        }
        usort($found, static fn (RosterDay $a, RosterDay $b): int => $a->date()->dayNumber() <=> $b->date()->dayNumber());

        return $found;
    }

    public function onDate(string $workerAssignmentId, WorkDate $date): ?RosterDay
    {
        return $this->days[$workerAssignmentId][(string) $date] ?? null;
    }

    public function apply(string $workerAssignmentId, array $days, array $datesToClear): void
    {
        ++$this->applyCalls;
        foreach ($datesToClear as $date) {
            unset($this->days[$workerAssignmentId][(string) $date]);
        }
        foreach ($days as $day) {
            $this->days[$day->workerAssignmentId()][(string) $day->date()] = $day;
        }
        foreach ($this->days as $assignment => $byDate) {
            ksort($byDate);
            $this->days[$assignment] = $byDate;
        }
    }

    public function firstFrom(string $workerAssignmentId, WorkDate $from, int $withinDays): ?RosterDay
    {
        return $this->inRange($workerAssignmentId, $from, $from->plusDays($withinDays))[0] ?? null;
    }

    public function countFor(string $workerAssignmentId): int
    {
        return \count($this->days[$workerAssignmentId] ?? []);
    }
}
