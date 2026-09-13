<?php

declare(strict_types=1);

namespace App\Swap\Application\Query;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Application\SwapWorkspace;
use App\Swap\Domain\Availabilities;
use App\Swap\Domain\WorkDateLabel;

final readonly class GetMyAvailabilityHandler
{
    public function __construct(private SwapWorkspace $workspace, private Availabilities $availabilities)
    {
    }

    /** @return list<AvailabilityDayView> */
    public function __invoke(GetMyAvailability $query): array
    {
        $groups = $this->workspace->groupsFor($query->workerId);
        if ([] === $groups) {
            return [];
        }

        $byPool = [];
        foreach ($groups as $group) {
            $byPool[$group->poolId] = $group;
        }

        /**
         * @var array<string, array{
         *     date: \App\Swap\Domain\WorkDate,
         *     ids: array<string, true>,
         *     kinds: array<string, ShiftKind>,
         *     pools: array<string, true>,
         *     places: array<string, array{
         *         destinations: array<string, true>,
         *         categories: array<string, true>,
         *         pools: array<string, true>
         *     }>
         * }> $days
         */
        $days = [];
        foreach ($this->availabilities->activeByWorker($query->workerId, $this->workspace->today($groups[0])) as $availability) {
            $group = $byPool[$availability->swapPoolId()] ?? null;
            if (null === $group) {
                continue;
            }
            $date = (string) $availability->workDate();
            $days[$date] ??= ['date' => $availability->workDate(), 'ids' => [], 'kinds' => [], 'pools' => [], 'places' => []];
            $days[$date]['ids'][$availability->id()] = true;
            $days[$date]['kinds'][$availability->shiftKind()->value] = $availability->shiftKind();
            $days[$date]['pools'][$group->poolId] = true;
            if (!isset($days[$date]['places'][$group->workplaceName])) {
                $days[$date]['places'][$group->workplaceName] = ['destinations' => [], 'categories' => [], 'pools' => []];
            }
            $place = &$days[$date]['places'][$group->workplaceName];
            $destination = $group->destinationName ?: $group->functionalArea;
            if ('' !== $destination) {
                $place['destinations'][$destination] = true;
            }
            if ('' !== $group->categoryName) {
                $place['categories'][$group->categoryName] = true;
            }
            $place['pools'][$group->poolId] = true;
            unset($place);
        }

        $views = [];
        foreach ($days as $day) {
            $kinds = array_values($day['kinds']);
            usort($kinds, static fn (ShiftKind $a, ShiftKind $b): int => array_search($a, ShiftKind::offerable(), true) <=> array_search($b, ShiftKind::offerable(), true));
            $places = [];
            foreach ($day['places'] as $workplace => $place) {
                $destinations = array_keys($place['destinations']);
                if ([] === $destinations) {
                    $count = \count($place['pools']);
                    $destinations = [1 === $count ? 'Destino anterior (nombre no disponible)' : $count.' destinos anteriores (nombres no disponibles)'];
                }
                $places[] = new AvailabilityPlaceView($workplace, $destinations, array_keys($place['categories']), array_keys($place['pools']));
            }
            $views[] = new AvailabilityDayView(
                array_keys($day['ids']),
                (string) $day['date'],
                WorkDateLabel::headline($day['date']),
                array_map(static fn (ShiftKind $kind): string => $kind->value, $kinds),
                array_map(static fn (ShiftKind $kind): string => $kind->label(), $kinds),
                $places,
                array_keys($day['pools']),
            );
        }

        return $views;
    }
}
