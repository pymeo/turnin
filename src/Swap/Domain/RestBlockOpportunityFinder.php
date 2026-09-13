<?php

declare(strict_types=1);

namespace App\Swap\Domain;

final readonly class RestBlockOpportunityFinder
{
    public function __construct(private int $minimumResultingDays = 3)
    {
    }

    /** @param list<RosteredDay> $calendar
     * @return list<RestBlockOpportunity>
     */
    public function find(array $calendar): array
    {
        $byDate = [];
        foreach ($calendar as $day) {
            $byDate[$day->date] = $day;
        }
        $opportunities = [];
        foreach ($calendar as $day) {
            if (!$day->isWorking()) {
                continue;
            }
            $date = WorkDate::fromString($day->date);
            $left = $this->consecutiveRest($byDate, $date, -1);
            $right = $this->consecutiveRest($byDate, $date, 1);
            $resulting = $left + 1 + $right;
            if ($resulting < $this->minimumResultingDays) {
                continue;
            }
            $joinsBlocks = $left > 0 && $right > 0;
            $current = max($left, $right);
            $gained = $resulting - $current;
            $reasons = [$resulting.' días consecutivos libres', 'solo requiere cambiar un turno'];
            if ($joinsBlocks) {
                $reasons[] = 'une dos bloques de descanso';
            }
            $opportunities[] = new RestBlockOpportunity($day, $current, $resulting, (string) $date->plusDays(-$left), (string) $date->plusDays($right), $gained, $resulting * 10 + $gained * 5 + ($joinsBlocks ? 20 : 0), $reasons);
        }
        usort($opportunities, static fn (RestBlockOpportunity $a, RestBlockOpportunity $b): int => $b->score <=> $a->score);

        return $opportunities;
    }

    /** @param array<string, RosteredDay> $calendar */
    private function consecutiveRest(array $calendar, WorkDate $date, int $direction): int
    {
        $count = 0;
        while (true) {
            $candidate = (string) $date->plusDays($direction * ($count + 1));
            if (($calendar[$candidate] ?? null)?->state !== RosteredDayState::REST) {
                return $count;
            }
            ++$count;
        }
    }
}
