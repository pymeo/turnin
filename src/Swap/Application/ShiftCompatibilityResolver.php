<?php

declare(strict_types=1);

namespace App\Swap\Application;

use App\Swap\Domain\RosteredDays;
use App\Swap\Domain\RosteredShift;
use App\Swap\Domain\ShiftCompatibility;
use App\Swap\Domain\WorkDate;
use Psr\Clock\ClockInterface;

/**
 * "Can this person work these shifts?", answered for a whole screen at once.
 *
 * Every screen in the exchange flow needs the same answer about a handful of
 * shifts, and asking per card is how a list of twenty becomes twenty queries.
 * One range read of the candidate's own calendar — padded by a day on each side
 * so the rest rule can see the shift before and after — answers all of them.
 */
final readonly class ShiftCompatibilityResolver
{
    public function __construct(private RosteredDays $days, private ClockInterface $clock)
    {
    }

    /**
     * @param list<string>        $candidateAssignmentIds every calendar the candidate owns
     * @param list<RosteredShift> $targets                the work to be taken on
     * @param array<string, bool> $reachable              day key => the candidate belongs to that group
     * @param array<string, true> $releasing              day keys of the candidate's own that this
     *                                                    very exchange would take off their hands, and
     *                                                    which therefore cannot be what blocks it
     *
     * @return array<string, ShiftCompatibility> keyed by {@see RosteredShift::dayKey()}
     */
    public function assess(array $candidateAssignmentIds, array $targets, array $reachable, array $releasing = []): array
    {
        if ([] === $targets) {
            return [];
        }

        $byDay = [];
        $dates = [];
        foreach ($targets as $shift) {
            $byDay[$shift->dayKey()][] = $shift;
            $dates[] = $shift->date;
        }
        sort($dates);

        $own = array_values(array_filter(
            $this->days->shiftsInRange(
                $candidateAssignmentIds,
                (string) WorkDate::fromString($dates[0])->plusDays(-1),
                (string) WorkDate::fromString($dates[\count($dates) - 1])->plusDays(1),
            ),
            static fn (RosteredShift $shift): bool => !isset($releasing[$shift->dayKey()]),
        ));
        $now = $this->clock->now();

        $verdicts = [];
        foreach ($byDay as $key => $shifts) {
            $verdicts[$key] = ShiftCompatibility::assess($shifts, $own, $reachable[$key] ?? false, $now);
        }

        return $verdicts;
    }
}
