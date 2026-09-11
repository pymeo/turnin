<?php

declare(strict_types=1);

namespace App\Scheduling\Domain;

/**
 * Decides, day by day, what a draft would actually do.
 *
 * The rule that matters: a day the worker already filled in is never
 * overwritten unless they said so. Everything else here follows from that.
 */
final readonly class ScheduleDraftResolver
{
    /**
     * @param list<RosterDay> $existing
     * @param list<string>    $unrecognized
     */
    public function resolve(ScheduleDraft $draft, array $existing, ConflictPolicy $policy, array $unrecognized = []): ResolvedScheduleDraft
    {
        $byDate = [];
        foreach ($existing as $day) {
            $byDate[(string) $day->date()] = $day;
        }

        $entries = [];
        foreach ($draft->entries as $entry) {
            if (!$entry->isApplicable()) {
                continue;
            }

            $current = $byDate[(string) $entry->date] ?? null;

            // Clearing a day is an explicit, single-day instruction from the day
            // sheet. It is the one intent whose whole purpose is to remove what
            // is there, so it is never treated as a conflict with itself.
            if (DraftIntent::CLEAR === $entry->intent) {
                $entries[] = new ResolvedDraftEntry($entry, $current, false, null !== $current);
                continue;
            }

            if (null === $current) {
                $entries[] = new ResolvedDraftEntry($entry, null, false, true);
                continue;
            }

            // Proposing exactly what is already stored is not a conflict and not
            // a write: it is a no-op, and counting it would inflate "12 días
            // modificados" with days nothing happened to.
            if ($this->alreadySatisfied($entry, $current)) {
                $entries[] = new ResolvedDraftEntry($entry, $current, false, false);
                continue;
            }

            $entries[] = new ResolvedDraftEntry($entry, $current, true, ConflictPolicy::REPLACE_EXISTING === $policy);
        }

        return new ResolvedScheduleDraft($entries, $policy, $unrecognized);
    }

    private function alreadySatisfied(ScheduleDraftEntry $entry, RosterDay $current): bool
    {
        if (DraftIntent::REST === $entry->intent) {
            return $current->isRest();
        }

        $segments = $current->segments();
        if (!$current->isWorking() || \count($segments) !== \count($entry->segments)) {
            return false;
        }

        foreach ($entry->segments as $index => $proposal) {
            $segment = $segments[$index];
            if ($segment->abbreviationSnapshot !== $proposal->abbreviation || $segment->kind !== $proposal->kind || !$segment->window->equals($proposal->window)) {
                return false;
            }
        }

        return true;
    }
}
