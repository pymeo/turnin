<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * "Parece que este patrón se repite cada 9 días.".
 *
 * Exact repetition only, over consecutive days that are all filled in. No
 * fuzziness and no model: a suggestion that is right 70% of the time costs more
 * trust than it saves taps, because the 30% quietly rewrites a quarter of
 * somebody's calendar.
 *
 * The rule is deliberately strict — at least two whole cycles, every day known,
 * no gaps — so that when the banner appears, it is right.
 */
final readonly class RepeatingRosterPatternDetector
{
    private const MINIMUM_CYCLE_LENGTH = 2;
    private const MINIMUM_CYCLES = 2;
    private const MAXIMUM_CYCLE_LENGTH = 31;

    /** @param list<RosterDay> $days */
    public function detect(array $days): ?DetectedRosterPattern
    {
        $run = $this->longestConsecutiveRun($days);
        $length = \count($run);
        if ($length < self::MINIMUM_CYCLE_LENGTH * self::MINIMUM_CYCLES) {
            return null;
        }

        $codes = array_map(fn (RosterDay $day): string => $this->codeFor($day), $run);

        for ($cycle = self::MINIMUM_CYCLE_LENGTH; $cycle <= min(self::MAXIMUM_CYCLE_LENGTH, intdiv($length, self::MINIMUM_CYCLES)); ++$cycle) {
            if (!$this->repeatsEvery($codes, $cycle)) {
                continue;
            }
            // A run of identical days is not a rotation, it is a long stretch.
            if (1 === \count(array_unique(\array_slice($codes, 0, $cycle)))) {
                continue;
            }

            return new DetectedRosterPattern(
                array_map(fn (RosterDay $day): PatternSlotProposal => $this->slotFor($day), \array_slice($run, 0, $cycle)),
                $run[\count($run) - 1]->date()->plusDays(1),
                intdiv($length, $cycle),
            );
        }

        return null;
    }

    /**
     * @param list<RosterDay> $days
     *
     * @return list<RosterDay>
     */
    private function longestConsecutiveRun(array $days): array
    {
        usort($days, static fn (RosterDay $a, RosterDay $b): int => $a->date()->dayNumber() <=> $b->date()->dayNumber());

        $best = [];
        $current = [];
        foreach ($days as $day) {
            $previous = $current[\count($current) - 1] ?? null;
            if (null !== $previous && 1 !== $previous->date()->daysUntil($day->date())) {
                $current = [];
            }
            $current[] = $day;
            if (\count($current) > \count($best)) {
                $best = $current;
            }
        }

        return $best;
    }

    /** @param list<string> $codes */
    private function repeatsEvery(array $codes, int $cycle): bool
    {
        $usable = intdiv(\count($codes), $cycle) * $cycle;
        for ($index = $cycle; $index < $usable; ++$index) {
            if ($codes[$index] !== $codes[$index - $cycle]) {
                return false;
            }
        }

        return true;
    }

    private function codeFor(RosterDay $day): string
    {
        if ($day->isRest()) {
            return 'rest';
        }

        return implode('+', array_map(static fn (ShiftSegment $segment): string => $segment->presetId ?? $segment->abbreviationSnapshot.$segment->window, $day->segments()));
    }

    private function slotFor(RosterDay $day): PatternSlotProposal
    {
        if ($day->isRest()) {
            return PatternSlotProposal::rest();
        }
        $segment = $day->firstSegment();
        if (null === $segment) {
            return new PatternSlotProposal(PatternSlotType::SHIFT, null, 'Turno', '?');
        }

        return PatternSlotProposal::fromSegment($segment);
    }
}
