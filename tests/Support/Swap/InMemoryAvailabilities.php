<?php

declare(strict_types=1);

namespace App\Tests\Support\Swap;

use App\SharedKernel\Domain\ShiftKind;
use App\Swap\Domain\Availabilities;
use App\Swap\Domain\Availability;
use App\Swap\Domain\WorkDate;

final class InMemoryAvailabilities implements Availabilities
{
    /** @var array<string, Availability> keyed the way the unique index is */
    private array $availabilities = [];

    /** @param list<Availability> $seed */
    public function __construct(array $seed = [])
    {
        foreach ($seed as $availability) {
            $this->save($availability);
        }
    }

    public function save(Availability $availability): void
    {
        $this->availabilities[$this->key($availability->workerId(), $availability->swapPoolId(), (string) $availability->workDate(), $availability->shiftKind())] = $availability;
    }

    public function byId(string $id): ?Availability
    {
        foreach ($this->availabilities as $availability) {
            if ($availability->id() === $id) {
                return $availability;
            }
        }

        return null;
    }

    public function forSlot(string $workerId, string $swapPoolId, WorkDate $date, ShiftKind $shiftKind): ?Availability
    {
        return $this->availabilities[$this->key($workerId, $swapPoolId, (string) $date, $shiftKind)] ?? null;
    }

    public function activeByWorkerOnDate(string $workerId, WorkDate $date): array
    {
        return array_values(array_filter($this->availabilities, static fn (Availability $availability): bool => $availability->isActive() && $availability->workerId() === $workerId && $availability->workDate()->equals($date)));
    }

    public function activeByWorker(string $workerId, WorkDate $from): array
    {
        $found = [];
        foreach ($this->availabilities as $availability) {
            if ($availability->isActive() && $availability->workerId() === $workerId && $availability->workDate()->isAfter($from)) {
                $found[] = $availability;
            }
        }

        return $found;
    }

    public function activeInPoolOnDate(string $swapPoolId, WorkDate $date, ShiftKind $shiftKind, string $excludingWorkerId): array
    {
        $found = [];
        foreach ($this->availabilities as $availability) {
            if ($availability->isActive()
                && $availability->swapPoolId() === $swapPoolId
                && $availability->workDate()->equals($date)
                && $availability->shiftKind() === $shiftKind
                && $availability->workerId() !== $excludingWorkerId) {
                $found[] = $availability;
            }
        }

        return $found;
    }

    public function activePoolsOnDate(string $workerId, WorkDate $date): array
    {
        $pools = [];
        foreach ($this->availabilities as $availability) {
            if ($availability->isActive() && $availability->workerId() === $workerId && $availability->workDate()->equals($date)) {
                $pools[$availability->swapPoolId()] = $availability->swapPoolId();
            }
        }

        return array_values($pools);
    }

    public function activeDatesFor(string $workerId, WorkDate $from, WorkDate $to): array
    {
        $dates = [];
        foreach ($this->availabilities as $availability) {
            $date = (string) $availability->workDate();
            if ($availability->isActive() && $availability->workerId() === $workerId && $date >= (string) $from && $date <= (string) $to) {
                $dates[$date] = $date;
            }
        }

        return array_values($dates);
    }

    public function activePoolsOnDates(string $workerId, array $dates): array
    {
        $byDate = [];
        foreach ($dates as $date) {
            foreach ($this->activeByWorkerOnDate($workerId, $date) as $availability) {
                $byDate[$date.'|'.$availability->shiftKind()->value][$availability->swapPoolId()] = $availability->swapPoolId();
            }
        }

        return array_map('array_values', $byDate);
    }

    public function count(): int
    {
        return \count($this->availabilities);
    }

    private function key(string $workerId, string $poolId, string $date, ShiftKind $shiftKind): string
    {
        return $workerId.'|'.$poolId.'|'.$date.'|'.$shiftKind->value;
    }
}
