<?php

declare(strict_types=1);

namespace App\Tests\Support\Swap;

use App\Swap\Domain\SwapGroup;
use App\Swap\Domain\SwapGroups;

/**
 * Memberships as a fixture. Deliberately answers *only* for the worker that
 * owns each group: the whole point of the port in the tests is proving that a
 * pool belonging to somebody else resolves to nothing.
 */
final readonly class FixedSwapGroups implements SwapGroups
{
    /** @param array<string, list<SwapGroup>> $byWorker */
    public function __construct(private array $byWorker)
    {
    }

    public function activeFor(string $workerId): array
    {
        return $this->byWorker[$workerId] ?? [];
    }

    public function forAssignment(string $workerId, string $assignmentId): array
    {
        return array_values(array_filter(
            $this->activeFor($workerId),
            static fn (SwapGroup $group): bool => $group->assignmentId === $assignmentId,
        ));
    }

    public function membership(string $workerId, string $swapPoolId): ?SwapGroup
    {
        foreach ($this->activeFor($workerId) as $group) {
            if ($group->poolId === $swapPoolId) {
                return $group;
            }
        }

        return null;
    }
}
